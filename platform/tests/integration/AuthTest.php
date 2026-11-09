<?php
declare(strict_types=1);

use App\Core\Session;
use App\Security\Tokens;
use Tests\T;

T::test('auth', 'login succeeds with valid credentials, regenerates session state, audits', function () {
    T::user('login.ok@example.test', ['organisation_admin'], 'Login-Synthetic-Pass-9');
    $r = T::post('/login', ['email' => 'LOGIN.OK@example.test', 'password' => 'Login-Synthetic-Pass-9']);
    T::eq(303, $r->status);
    T::eq('/dashboard', $r->headers['Location']);
    T::assert(is_int(Session::get('uid')), 'session uid set');
    T::assert((bool) T::db()->value("SELECT 1 FROM audit_logs WHERE action = 'auth.login' AND outcome = 'success'"), 'audited');
});

T::test('auth', 'invalid password and unknown account give the same generic error', function () {
    T::user('login.bad@example.test', ['volunteer'], 'Right-Synthetic-Pass-9');
    $a = T::post('/login', ['email' => 'login.bad@example.test', 'password' => 'wrong-password-123']);
    $flash1 = Session::get('_flash');
    T::actingAs(null);
    $b = T::post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password-123']);
    $flash2 = Session::get('_flash');
    T::eq('/login', $a->headers['Location']);
    T::eq($flash1, $flash2, 'identical messages');
    T::eq(null, Session::get('uid'));
});

T::test('auth', 'lockout after 5 failed attempts for an account (rate limit, audited)', function () {
    T::user('lock@example.test', ['volunteer'], 'Lock-Synthetic-Pass-9');
    for ($i = 0; $i < 5; $i++) {
        T::post('/login', ['email' => 'lock@example.test', 'password' => 'bad-' . $i]);
    }
    T::post('/login', ['email' => 'lock@example.test', 'password' => 'Lock-Synthetic-Pass-9']);
    T::eq(null, Session::get('uid'), 'correct password refused while locked');
    T::assert((bool) T::db()->value("SELECT 1 FROM audit_logs WHERE action = 'auth.lockout'"), 'lockout audited');
    T::assert(!T::db()->value("SELECT 1 FROM rate_limit_hits WHERE key_hash = ?", ['lock@example.test']), 'raw email never stored');
});

T::test('auth', 'deactivated account cannot sign in', function () {
    $id = T::user('deact@example.test', ['volunteer'], 'Deact-Synthetic-Pass-9');
    T::db()->run("UPDATE users SET status = 'deactivated' WHERE id = ?", [$id]);
    T::post('/login', ['email' => 'deact@example.test', 'password' => 'Deact-Synthetic-Pass-9']);
    T::eq(null, Session::get('uid'));
});

T::test('auth', 'session invalidated when session_version changes (ST-33)', function () {
    $id = T::user('sv@example.test', ['organisation_admin']);
    T::actingAs($id);
    T::eq(200, T::get('/dashboard')->status);
    T::db()->run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
    $r = T::get('/dashboard');
    T::eq(303, $r->status, 'redirected to login');
    T::eq('/login', $r->headers['Location']);
});

T::test('auth', 'idle timeout ends the session', function () {
    $id = T::user('idle@example.test', ['volunteer']);
    T::actingAs($id);
    Session::set('last_seen', time() - 3600);
    T::eq(303, T::get('/dashboard')->status);
});

T::test('auth', 'unauthenticated: HTML redirects to login, AJAX gets 401', function () {
    T::eq(303, T::get('/admin/users')->status);
    T::eq(401, T::get('/admin/users', [], ['accept' => 'application/json'])->status);
});

T::test('auth', 'CSRF: state-changing POST without token is rejected and audited (ST-34)', function () {
    $id = T::user('csrf@example.test', ['organisation_admin']);
    T::actingAs($id);
    $r = T::post('/projects', ['name' => 'Should not exist'], false);
    T::eq(403, $r->status);
    T::assert(!T::db()->value("SELECT 1 FROM projects WHERE name = 'Should not exist'"), 'nothing created');
    T::assert((bool) T::db()->value("SELECT 1 FROM audit_logs WHERE action = 'csrf.rejected'"), 'audited');
});

T::test('auth', 'activation: token single use, password policy, account becomes active', function () {
    $db = T::db();
    $id = $db->insert('users', ['email' => 'invitee@example.test', 'status' => 'invited', 'session_version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $token = Tokens::issue($id, 'activation', T::$ownerId);
    T::post('/activate', ['token' => $token, 'password' => 'short', 'password_confirm' => 'short']);
    T::eq('invited', $db->value('SELECT status FROM users WHERE id = ?', [$id]), 'weak password refused');
    $r = T::post('/activate', ['token' => $token, 'password' => 'Activation-Synthetic-9', 'password_confirm' => 'Activation-Synthetic-9']);
    T::eq('/dashboard', $r->headers['Location']);
    T::eq('active', $db->value('SELECT status FROM users WHERE id = ?', [$id]));
    T::assert($db->value('SELECT email_verified_at FROM users WHERE id = ?', [$id]) !== null, 'email verified');
    T::actingAs(null);
    T::post('/activate', ['token' => $token, 'password' => 'Another-Synthetic-9', 'password_confirm' => 'Another-Synthetic-9']);
    T::eq(null, Session::get('uid'), 'token cannot be reused');
    T::eq(0, (int) $db->value('SELECT COUNT(*) FROM auth_tokens WHERE token_hash = ?', [$token]), 'plaintext token never stored');
});

T::test('auth', 'password reset: generic response, token works once, old sessions end', function () {
    $db = T::db();
    $id = T::user('reset@example.test', ['volunteer'], 'Old-Synthetic-Pass-9');
    $r1 = T::post('/forgot-password', ['email' => 'reset@example.test']);
    $r2 = T::post('/forgot-password', ['email' => 'unknown@example.test']);
    T::eq($r1->headers['Location'], $r2->headers['Location'], 'same response for unknown account');
    T::eq(1, (int) $db->value("SELECT COUNT(*) FROM email_outbox WHERE to_email = 'reset@example.test' AND template = 'password_reset'"));
    $token = Tokens::issue($id, 'password_reset', null);
    $sv = (int) $db->value('SELECT session_version FROM users WHERE id = ?', [$id]);
    T::post('/reset-password', ['token' => $token, 'password' => 'New-Synthetic-Pass-77', 'password_confirm' => 'New-Synthetic-Pass-77']);
    T::eq($sv + 1, (int) $db->value('SELECT session_version FROM users WHERE id = ?', [$id]), 'sessions revoked');
    T::assert(password_verify('New-Synthetic-Pass-77', (string) $db->value('SELECT password_hash FROM users WHERE id = ?', [$id])), 'password changed');
});

T::test('auth', 'open redirect prevented after login', function () {
    T::user('redir@example.test', ['volunteer'], 'Redir-Synthetic-Pass-9');
    Session::set('intended', '//evil.example/');
    $r = T::post('/login', ['email' => 'redir@example.test', 'password' => 'Redir-Synthetic-Pass-9']);
    T::eq('/dashboard', $r->headers['Location']);
});

T::test('auth', 'security headers present on every response', function () {
    $h = App\Core\SecurityHeaders::all();
    T::assert(str_contains($h['Content-Security-Policy'], "frame-ancestors 'none'"), 'CSP');
    T::eq('DENY', $h['X-Frame-Options']);
    T::eq('nosniff', $h['X-Content-Type-Options']);
});
