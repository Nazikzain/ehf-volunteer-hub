<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Database;

/** Activation and password-reset tokens: only SHA-256 hashes are stored (Design §5 A5). */
final class Tokens
{
    public const TTL = ['activation' => 172800, 'password_reset' => 3600];

    public static function issue(int $userId, string $purpose, ?int $createdBy): string
    {
        $db = Database::get();
        $db->run('UPDATE auth_tokens SET revoked_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL AND revoked_at IS NULL',
            [now_utc(), $userId, $purpose]);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db->insert('auth_tokens', ['user_id' => $userId, 'purpose' => $purpose, 'token_hash' => hash('sha256', $token, true),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL[$purpose]), 'created_by' => $createdBy, 'created_at' => now_utc()]);
        return $token;
    }

    /** Returns the token row if valid (unused, unrevoked, unexpired), else null. */
    public static function find(string $token, string $purpose): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{30,64}$/', $token)) {
            return null;
        }
        return Database::get()->one('SELECT * FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL
            AND revoked_at IS NULL AND expires_at > ?', [hash('sha256', $token, true), $purpose, now_utc()]);
    }

    public static function markUsed(int $id): void
    {
        Database::get()->run('UPDATE auth_tokens SET used_at = ? WHERE id = ?', [now_utc(), $id]);
    }

    public static function purge(): int
    {
        return Database::get()->run('DELETE FROM auth_tokens WHERE (used_at IS NOT NULL OR revoked_at IS NOT NULL OR expires_at < ?) AND created_at < ?',
            [now_utc(), gmdate('Y-m-d H:i:s', time() - 30 * 86400)])->rowCount();
    }
}
