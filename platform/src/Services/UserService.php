<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;
use App\Security\Tokens;

/** User administration with Primary Admin protection (Design Rev3 §4.5, §7 W1-W3, §8). */
final class UserService
{
    public const STAFF_ROLES = ['organisation_admin' => 1, 'project_supervisor' => 2];

    public static function list(array $f): array
    {
        $where = ['1=1'];
        $p = [];
        if (!empty($f['status'])) { $where[] = 'u.status = :st'; $p['st'] = $f['status']; }
        if (!empty($f['role'])) { $where[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.code = :role)'; $p['role'] = $f['role']; }
        if (!empty($f['q'])) { $where[] = 'u.email LIKE :q'; $p['q'] = '%' . $f['q'] . '%'; }
        return Database::get()->all('SELECT u.id, u.email, u.status, u.last_login_at, u.created_at,
            (SELECT GROUP_CONCAT(r.code ORDER BY r.id) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS roles,
            (SELECT 1 FROM system_owner s WHERE s.user_id = u.id) AS is_owner
            FROM users u WHERE ' . implode(' AND ', $where) . ' ORDER BY u.email LIMIT 500', $p);
    }

    public static function get(int $id): array
    {
        $db = Database::get();
        $u = $db->one('SELECT id, email, status, email_verified_at, last_login_at, created_at, deactivated_at, anonymised_at FROM users WHERE id = ?', [$id]);
        if (!$u) {
            Gate::notFound('user');
        }
        $u['roles'] = array_column($db->all('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$id]), 'code');
        $u['is_owner'] = (bool) $db->value('SELECT 1 FROM system_owner WHERE user_id = ?', [$id]);
        $u['volunteer'] = $db->one('SELECT id, full_name FROM volunteers WHERE user_id = ?', [$id]);
        $u['supervises'] = $db->all('SELECT p.id, p.name, ps.ended_at FROM project_supervisors ps JOIN projects p ON p.id = ps.project_id WHERE ps.user_id = ? ORDER BY p.name', [$id]);
        return $u;
    }

    private static function isOwner(Database $db, int $id): bool
    {
        return (bool) $db->value('SELECT 1 FROM system_owner WHERE user_id = ?', [$id]);
    }

    /** Owner protection (OwnershipGuard): nobody but the owner, via transfer, may act on the owner. */
    private static function guard(Database $db, int $targetId, string $what): void
    {
        if (self::isOwner($db, $targetId)) {
            Gate::deny(403, ['reason' => 'primary_admin_protected', 'action' => $what]);
        }
    }

    public static function invite(string $email, string $role): int
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException(['Enter a valid email address.']);
        }
        if (!isset(self::STAFF_ROLES[$role])) {
            throw new ValidationException(['Choose a valid role. Volunteers receive accounts through application approval.']);
        }
        return Database::get()->transaction(function (Database $db) use ($email, $role) {
            if ($db->value('SELECT 1 FROM users WHERE email = ?', [$email])) {
                throw new ValidationException(['An account with this email already exists. Grant the role from its page instead.']);
            }
            $now = now_utc();
            $id = $db->insert('users', ['email' => $email, 'status' => 'invited', 'session_version' => 1, 'created_by' => Auth::id(),
                'created_at' => $now, 'updated_at' => $now]);
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => self::STAFF_ROLES[$role], 'granted_by' => Auth::id(), 'granted_at' => $now]);
            $token = Tokens::issue($id, 'activation', Auth::id());
            Mailer::queue($email, 'staff_invitation', 'You have been invited to the EHF Volunteer Hub',
                "You have been invited to the EHF Volunteer Hub as " . label($role) . ".\n\nActivate your account (link valid 48 hours):\n"
                . rtrim((string) env('APP_URL'), '/') . "/activate?token={$token}\n\nEthnic Health Forum");
            AuditLogger::record('user.invited', 'success', 'user', $id, ['role' => $role]);
            return $id;
        });
    }

    public static function reissueActivation(int $id): void
    {
        Database::get()->transaction(function (Database $db) use ($id) {
            $u = $db->one('SELECT id, email, status FROM users WHERE id = ? FOR UPDATE', [$id]);
            if (!$u || $u['status'] !== 'invited') {
                throw new ValidationException(['Only invited accounts can receive a new activation link.']);
            }
            $token = Tokens::issue($id, 'activation', Auth::id());
            Mailer::queue($u['email'], 'activation_reissued', 'Your EHF Volunteer Hub activation link',
                "Activate your account (link valid 48 hours):\n" . rtrim((string) env('APP_URL'), '/') . "/activate?token={$token}");
            AuditLogger::record('user.activation_reissued', 'success', 'user', $id);
        });
    }

    public static function grantRole(int $id, string $role): void
    {
        if (!isset(self::STAFF_ROLES[$role])) {
            throw new ValidationException(['Unknown role.']);
        }
        Database::get()->transaction(function (Database $db) use ($id, $role) {
            if ($id === Auth::id()) {
                Gate::deny(403, ['reason' => 'self_role_change']);
            }
            self::guard($db, $id, 'role_grant');
            $db->run('INSERT IGNORE INTO user_roles (user_id, role_id, granted_by, granted_at) VALUES (?, ?, ?, ?)',
                [$id, self::STAFF_ROLES[$role], Auth::id(), now_utc()]);
            $db->run('UPDATE users SET session_version = session_version + 1, updated_at = ? WHERE id = ?', [now_utc(), $id]);
            AuditLogger::record('user.role_grant', 'success', 'user', $id, ['role' => $role]);
        });
    }

    public static function revokeRole(int $id, string $role): void
    {
        $roleId = self::STAFF_ROLES[$role] ?? null;
        if ($roleId === null) {
            throw new ValidationException(['Unknown role.']);
        }
        Database::get()->transaction(function (Database $db) use ($id, $role, $roleId) {
            if ($id === Auth::id()) {
                Gate::deny(403, ['reason' => 'self_role_change']);
            }
            self::guard($db, $id, 'role_revoke');
            $db->run('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?', [$id, $roleId]);
            if ($role === 'project_supervisor') {
                $db->run('UPDATE project_supervisors SET ended_at = ?, ended_by = ? WHERE user_id = ? AND ended_at IS NULL', [now_utc(), Auth::id(), $id]);
            }
            $db->run('UPDATE users SET session_version = session_version + 1, updated_at = ? WHERE id = ?', [now_utc(), $id]);
            AuditLogger::record('user.role_revoke', 'success', 'user', $id, ['role' => $role]);
        });
    }

    /** W2: deactivate. Org Admins may deactivate anyone except the owner; Org Admins only by the owner. */
    public static function deactivate(int $id): void
    {
        Database::get()->transaction(function (Database $db) use ($id) {
            if ($id === Auth::id()) {
                throw new ValidationException(['You cannot deactivate your own account.']);
            }
            self::guard($db, $id, 'deactivate');
            $isOa = (bool) $db->value('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = 1', [$id]);
            if ($isOa && !Auth::isOwner()) {
                Gate::deny(403, ['reason' => 'only_owner_deactivates_org_admin']);
            }
            $n = $db->run("UPDATE users SET status = 'deactivated', deactivated_at = ?, session_version = session_version + 1, updated_at = ?
                WHERE id = ? AND status = 'active'", [now_utc(), now_utc(), $id])->rowCount();
            if ($n !== 1) {
                throw new ValidationException(['Only active accounts can be deactivated.']);
            }
            $db->run('UPDATE auth_tokens SET revoked_at = ? WHERE user_id = ? AND used_at IS NULL AND revoked_at IS NULL', [now_utc(), $id]);
            AuditLogger::record('user.deactivated', 'success', 'user', $id);
        });
    }

    public static function reactivate(int $id): void
    {
        Database::get()->transaction(function (Database $db) use ($id) {
            $n = $db->run("UPDATE users SET status = 'active', deactivated_at = NULL, updated_at = ? WHERE id = ? AND status = 'deactivated'
                AND anonymised_at IS NULL AND password_hash IS NOT NULL", [now_utc(), $id])->rowCount();
            if ($n !== 1) {
                throw new ValidationException(['This account cannot be reactivated.']);
            }
            AuditLogger::record('user.reactivated', 'success', 'user', $id);
        });
    }

    /** W1: delete a never-activated invitation with no references (RESTRICT FKs make this the DB test). */
    public static function deleteInvitation(int $id): void
    {
        $db = Database::get();
        try {
            $db->transaction(function (Database $db) use ($id) {
                self::guard($db, $id, 'delete');
                $u = $db->one('SELECT email, status FROM users WHERE id = ? FOR UPDATE', [$id]);
                if (!$u || $u['status'] !== 'invited') {
                    throw new ValidationException(['Only invitations that were never activated can be deleted. Deactivate or anonymise other accounts.']);
                }
                $db->run('UPDATE volunteers SET user_id = NULL WHERE user_id = ?', [$id]);
                $db->run("DELETE FROM email_outbox WHERE to_email = ? AND status IN ('queued')", [$u['email']]);
                $db->run('DELETE FROM users WHERE id = ?', [$id]);
                AuditLogger::record('user.invitation_deleted', 'success', 'user', $id);
            });
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1451) {
                throw new ValidationException(['This account is referenced by other records and cannot be deleted. Deactivate or anonymise it instead.']);
            }
            throw $e;
        }
    }

    /** W3: anonymise an account (not just the email). */
    public static function anonymise(int $id): void
    {
        Database::get()->transaction(function (Database $db) use ($id) {
            if ($id === Auth::id()) {
                throw new ValidationException(['You cannot anonymise your own account.']);
            }
            self::guard($db, $id, 'anonymise');
            $u = $db->one('SELECT email, anonymised_at FROM users WHERE id = ? FOR UPDATE', [$id]);
            if (!$u || $u['anonymised_at'] !== null) {
                throw new ValidationException(['This account cannot be anonymised.']);
            }
            self::anonymiseRow($db, $id, $u['email']);
            AuditLogger::record('user.anonymised', 'success', 'user', $id);
        });
    }

    /** Shared by W3 and W4. Caller holds the transaction. */
    public static function anonymiseRow(Database $db, int $id, string $oldEmail): void
    {
        $now = now_utc();
        $db->run("UPDATE users SET email = ?, password_hash = NULL, email_verified_at = NULL, last_login_at = NULL, status = 'deactivated',
            deactivated_at = COALESCE(deactivated_at, ?), anonymised_at = ?, session_version = session_version + 1, updated_at = ? WHERE id = ?",
            ["deleted-user-{$id}@invalid", $now, $now, $now, $id]);
        $db->run('DELETE FROM user_roles WHERE user_id = ?', [$id]);
        $db->run('DELETE FROM auth_tokens WHERE user_id = ?', [$id]);
        $db->run('DELETE FROM notifications WHERE user_id = ?', [$id]);
        $db->run("DELETE FROM email_outbox WHERE to_email = ? AND status IN ('queued', 'sending')", [$oldEmail]);
        $db->run('UPDATE project_supervisors SET ended_at = ?, ended_by = ? WHERE user_id = ? AND ended_at IS NULL', [$now, Auth::id(), $id]);
    }

    /** Ownership transfer: owner only, password re-entry, target must be an active Organisation Admin. */
    public static function transferOwnership(int $targetId, string $password, bool $confirm1, bool $confirm2): void
    {
        if (!Auth::isOwner()) {
            Gate::deny(403, ['reason' => 'owner_only']);
        }
        if (!$confirm1 || !$confirm2) {
            throw new ValidationException(['Both confirmations are required to transfer ownership.']);
        }
        Database::get()->transaction(function (Database $db) use ($targetId, $password) {
            $me = $db->one('SELECT id, password_hash FROM users WHERE id = ?', [Auth::id()]);
            if (!password_verify($password, (string) $me['password_hash'])) {
                throw new ValidationException(['Your password was not correct.']);
            }
            $t = $db->one("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id AND ur.role_id = 1 WHERE u.id = ? AND u.status = 'active'", [$targetId]);
            if (!$t || $targetId === Auth::id()) {
                throw new ValidationException(['The new Primary Admin must be a different, active Organisation Admin.']);
            }
            $db->run("SELECT * FROM system_owner WHERE singleton = 'owner' FOR UPDATE");
            $db->run("UPDATE system_owner SET user_id = ?, since = ?, transferred_by = ? WHERE singleton = 'owner'", [$targetId, now_utc(), Auth::id()]);
            $db->run('INSERT IGNORE INTO user_roles (user_id, role_id, granted_by, granted_at) VALUES (?, 1, ?, ?)', [Auth::id(), Auth::id(), now_utc()]);
            $db->run('UPDATE users SET session_version = session_version + 1 WHERE id IN (?, ?)', [Auth::id(), $targetId]);
            AuditLogger::record('owner.transfer', 'success', 'user', $targetId, ['from_user' => (string) Auth::id(), 'to_user' => (string) $targetId]);
        });
    }
}
