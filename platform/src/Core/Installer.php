<?php
declare(strict_types=1);

namespace App\Core;

use App\Audit\AuditLogger;
use App\Audit\Canonical;
use App\Security\Tokens;

/**
 * One-time installation (Architecture Rev3 §6.2): migrations, audit genesis, Primary Admin seed,
 * single activation link. Refuses to run twice (settings install lock).
 */
final class Installer
{
    public static function checkVersion(Database $db): array
    {
        $v = (string) $db->value('SELECT VERSION()');
        $isMaria = stripos($v, 'mariadb') !== false;
        preg_match('/^(\d+)\.(\d+)\.(\d+)/', $v, $m);
        $num = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $ok = $isMaria ? $num >= [10, 6, 0] : $num >= [8, 0, 16];
        return ['version' => $v, 'engine' => $isMaria ? 'MariaDB' : 'MySQL', 'supported' => $ok];
    }

    /** @return array{activation_token:string, owner_id:int} */
    public static function install(Database $db, string $ownerEmail): array
    {
        $ver = self::checkVersion($db);
        if (!$ver['supported']) {
            throw new \RuntimeException("Unsupported database {$ver['version']}: requires MySQL >= 8.0.16 or MariaDB >= 10.6");
        }
        (new Migrator($db))->migrate(APP_ROOT . '/database/migrations');
        if ($db->value("SELECT value FROM settings WHERE `key` = 'install.locked_at'")) {
            throw new \RuntimeException('Already installed (install lock present)');
        }
        $ownerEmail = strtolower(trim($ownerEmail));
        if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid owner email');
        }
        return $db->transaction(function (Database $d) use ($ownerEmail) {
            $d->run("INSERT INTO audit_chain (singleton, last_seq, last_hash, updated_at) VALUES ('head', 0, :h, :u)",
                ['h' => hex2bin(Canonical::GENESIS), 'u' => now_utc(true)]);
            $uid = $d->insert('users', ['email' => $ownerEmail, 'status' => 'invited', 'session_version' => 1,
                'created_at' => now_utc(), 'updated_at' => now_utc()]);
            $d->insert('user_roles', ['user_id' => $uid, 'role_id' => 1, 'granted_by' => null, 'granted_at' => now_utc()]);
            $d->insert('system_owner', ['singleton' => 'owner', 'user_id' => $uid, 'since' => now_utc(), 'transferred_by' => null]);
            $d->insert('settings', ['key' => 'install.locked_at', 'value' => now_utc(), 'updated_by' => null, 'updated_at' => now_utc()]);
            AuditLogger::record('system.installed', 'success', 'user', $uid, ['owner_user' => (string) $uid]);
            $token = Tokens::issue($uid, 'activation', null);
            AuditLogger::record('user.invited', 'success', 'user', $uid, ['role' => 'organisation_admin', 'owner' => 'true']);
            return ['activation_token' => $token, 'owner_id' => $uid];
        });
    }
}
