<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\Session;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\PasswordPolicy;
use App\Security\RateLimiter;
use App\Security\Tokens;

final class AuthService
{
    public const GENERIC_LOGIN_ERROR = 'The email address or password is incorrect, or the account is not active.';

    public static function attempt(string $email, string $password, string $ip): bool
    {
        $email = strtolower(trim($email));
        $db = Database::get();
        if (RateLimiter::tooMany('login', $email) || RateLimiter::tooMany('login', 'ip:' . $ip, 30)) {
            AuditLogger::record('auth.lockout', 'denied', null, null, ['reason' => 'rate_limited']);
            throw new ValidationException(['Too many sign-in attempts. Please wait 15 minutes and try again.']);
        }
        $u = $db->one('SELECT id, email, status, password_hash, session_version FROM users WHERE email = ?', [$email]);
        // Always run a hash check to reduce user-enumeration timing differences.
        $hash = $u['password_hash'] ?? '$2y$10$ukwCrxq3T9PQvdre.IMfBOktmSyFouI2Ouve3RH6Kjle.CQ1D7da2';
        $ok = password_verify($password, $hash) && $u !== null && $u['status'] === 'active';
        if (!$ok) {
            RateLimiter::hit('login', $email);
            RateLimiter::hit('login', 'ip:' . $ip);
            AuditLogger::record('auth.login_failed', 'failed', $u ? 'user' : null, $u['id'] ?? null,
                ['reason' => $u === null ? 'unknown_account' : ($u['status'] !== 'active' ? 'inactive' : 'bad_password')]);
            return false;
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $db->run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        RateLimiter::clear('login', $email);
        self::startSession($u);
        $db->run('UPDATE users SET last_login_at = ? WHERE id = ?', [now_utc(), $u['id']]);
        AuditLogger::record('auth.login', 'success', 'user', $u['id']);
        return true;
    }

    public static function startSession(array $u): void
    {
        Session::regenerate();
        Session::set('uid', (int) $u['id']);
        Session::set('sv', (int) $u['session_version']);
        Session::set('login_at', time());
        Session::set('last_seen', time());
        Auth::setUser($u);
    }

    public static function logout(): void
    {
        if (Auth::check()) {
            AuditLogger::record('auth.logout', 'success', 'user', Auth::id());
        }
        Session::destroy();
        Auth::reset();
    }

    /** Activation: set password, activate, verify email, mark token used (one transaction). */
    public static function activate(string $token, string $password, string $confirm, string $ip): array
    {
        if (RateLimiter::tooMany('activation', 'ip:' . $ip)) {
            throw new ValidationException(['Too many attempts. Please wait and try again.']);
        }
        RateLimiter::hit('activation', 'ip:' . $ip);
        $t = Tokens::find($token, 'activation');
        if ($t === null) {
            throw new ValidationException(['This activation link is invalid or has expired. Please ask an administrator for a new one.']);
        }
        $db = Database::get();
        $u = $db->one('SELECT id, email, status, session_version FROM users WHERE id = ?', [$t['user_id']]);
        if ($u === null || $u['status'] !== 'invited') {
            throw new ValidationException(['This account cannot be activated.']);
        }
        if ($password !== $confirm) {
            throw new ValidationException(['The passwords do not match.']);
        }
        $errs = PasswordPolicy::check($password, $u['email']);
        if ($errs) {
            throw new ValidationException($errs);
        }
        $db->transaction(function (Database $d) use ($u, $t, $password) {
            $d->run("UPDATE users SET password_hash = ?, status = 'active', email_verified_at = ?, updated_at = ? WHERE id = ? AND status = 'invited'",
                [password_hash($password, PASSWORD_DEFAULT), now_utc(), now_utc(), $u['id']]);
            Tokens::markUsed((int) $t['id']);
            AuditLogger::record('user.activated', 'success', 'user', $u['id'], [], ['user_id' => $u['id'], 'email' => null, 'role' => null]);
        });
        return $db->one('SELECT id, email, status, session_version FROM users WHERE id = ?', [$u['id']]);
    }

    /** Always behaves the same whether or not the account exists. */
    public static function requestReset(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        if (RateLimiter::tooMany('password_reset', $email) || RateLimiter::tooMany('password_reset', 'ip:' . $ip, 10)) {
            return;
        }
        RateLimiter::hit('password_reset', $email);
        RateLimiter::hit('password_reset', 'ip:' . $ip);
        $u = Database::get()->one("SELECT id, email FROM users WHERE email = ? AND status = 'active'", [$email]);
        AuditLogger::record('auth.password_reset_requested', 'success', $u ? 'user' : null, $u['id'] ?? null);
        if ($u) {
            $token = Tokens::issue((int) $u['id'], 'password_reset', null);
            Mailer::queue($u['email'], 'password_reset', 'Reset your EHF Volunteer Hub password',
                "A password reset was requested for your EHF Volunteer Hub account.\n\nReset link (valid 60 minutes):\n"
                . rtrim((string) env('APP_URL'), '/') . "/reset-password?token={$token}\n\nIf you did not request this, you can ignore this email.");
        }
    }

    public static function resetPassword(string $token, string $password, string $confirm): void
    {
        $t = Tokens::find($token, 'password_reset');
        if ($t === null) {
            throw new ValidationException(['This reset link is invalid or has expired. Please request a new one.']);
        }
        $db = Database::get();
        $u = $db->one("SELECT id, email FROM users WHERE id = ? AND status = 'active'", [$t['user_id']]);
        if ($u === null) {
            throw new ValidationException(['This reset link is invalid or has expired.']);
        }
        if ($password !== $confirm) {
            throw new ValidationException(['The passwords do not match.']);
        }
        $errs = PasswordPolicy::check($password, $u['email']);
        if ($errs) {
            throw new ValidationException($errs);
        }
        $db->transaction(function (Database $d) use ($u, $t, $password) {
            // session_version++ ends every existing session (R-10)
            $d->run('UPDATE users SET password_hash = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), now_utc(), $u['id']]);
            Tokens::markUsed((int) $t['id']);
            AuditLogger::record('auth.password_reset_completed', 'success', 'user', $u['id'], [], ['user_id' => $u['id'], 'email' => null, 'role' => null]);
        });
    }
}
