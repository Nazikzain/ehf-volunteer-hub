<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Database;
use App\Core\Session;

/** The authenticated user for the current request. */
final class Auth
{
    private static ?array $user = null;
    private static array $roles = [];
    private static bool $owner = false;
    private static ?int $volunteerId = null;
    private static ?array $supervised = null;

    public static function reset(): void
    {
        self::$user = null;
        self::$roles = [];
        self::$owner = false;
        self::$volunteerId = null;
        self::$supervised = null;
    }

    /**
     * Load the session user. Returns false (and clears the session) if the account is no longer
     * active, its session_version changed (deactivation, role change, password change: R-10),
     * or the idle/absolute timeout passed.
     */
    public static function loadFromSession(): bool
    {
        self::reset();
        $id = Session::get('uid');
        if (!is_int($id)) {
            return false;
        }
        $now = time();
        if ($now - (int) Session::get('last_seen', 0) > Session::IDLE_SECONDS
            || $now - (int) Session::get('login_at', 0) > Session::ABSOLUTE_SECONDS) {
            Session::destroy();
            return false;
        }
        $u = Database::get()->one("SELECT id, email, status, session_version FROM users WHERE id = ?", [$id]);
        if ($u === null || $u['status'] !== 'active' || (int) $u['session_version'] !== (int) Session::get('sv')) {
            Session::destroy();
            return false;
        }
        Session::set('last_seen', $now);
        self::setUser($u);
        return true;
    }

    public static function setUser(array $u): void
    {
        $db = Database::get();
        self::$user = $u;
        self::$roles = array_column($db->all('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$u['id']]), 'code');
        self::$owner = (bool) $db->value('SELECT 1 FROM system_owner WHERE user_id = ?', [$u['id']]);
        $v = $db->value('SELECT id FROM volunteers WHERE user_id = ? AND deleted_at IS NULL', [$u['id']]);
        self::$volunteerId = $v === null ? null : (int) $v;
        self::$supervised = null;
    }

    public static function user(): ?array { return self::$user; }
    public static function id(): ?int { return self::$user ? (int) self::$user['id'] : null; }
    public static function check(): bool { return self::$user !== null; }
    public static function isOwner(): bool { return self::$owner; }
    public static function roles(): array { return self::$roles; }
    public static function hasRole(string $r): bool { return in_array($r, self::$roles, true); }
    public static function volunteerId(): ?int { return self::$volunteerId; }
    public static function isAdmin(): bool { return self::$owner || self::hasRole('organisation_admin'); }

    /** Projects this user currently supervises (ended_at IS NULL) — the supervisor scope. */
    public static function supervisedProjectIds(): array
    {
        if (self::$supervised === null) {
            self::$supervised = self::$user && self::hasRole('project_supervisor')
                ? array_map('intval', array_column(Database::get()->all(
                    'SELECT project_id FROM project_supervisors WHERE user_id = ? AND ended_at IS NULL', [self::id()]), 'project_id'))
                : [];
        }
        return self::$supervised;
    }

    public static function supervises(int $projectId): bool
    {
        return in_array($projectId, self::supervisedProjectIds(), true);
    }

    /** Effective role label for audit snapshots. */
    public static function effectiveRole(): ?string
    {
        if (!self::$user) {
            return null;
        }
        if (self::$owner) {
            return 'primary_admin';
        }
        foreach (['organisation_admin', 'project_supervisor', 'volunteer'] as $r) {
            if (self::hasRole($r)) {
                return $r;
            }
        }
        return 'none';
    }

    /** Actor snapshot: email for staff only (R3-10 / D-03). */
    public static function actorSnapshot(): array
    {
        if (!self::$user) {
            return ['user_id' => null, 'email' => null, 'role' => null];
        }
        $role = self::effectiveRole();
        $staff = in_array($role, ['primary_admin', 'organisation_admin', 'project_supervisor'], true);
        return ['user_id' => self::id(), 'email' => $staff ? self::$user['email'] : null, 'role' => $role];
    }
}
