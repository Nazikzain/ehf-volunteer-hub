<?php
declare(strict_types=1);

namespace App\Security;

use App\Core\Database;

/** Rate limiting with HMAC-hashed keys; raw emails and IPs are never stored (R-11). */
final class RateLimiter
{
    public const LIMITS = [
        'login' => [5, 900],            // per account key, per 15 min
        'password_reset' => [3, 3600],
        'registration' => [5, 3600],    // per IP
        'activation' => [10, 900],
    ];

    public static function key(string $value): string
    {
        $secret = (string) env('APP_KEY', '');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('APP_KEY must be set (32+ characters)');
        }
        return hash_hmac('sha256', strtolower(trim($value)), $secret, true);
    }

    public static function tooMany(string $action, string $value, ?int $max = null): bool
    {
        [$limit, $window] = self::LIMITS[$action];
        $since = gmdate('Y-m-d H:i:s', time() - $window);
        $n = (int) Database::get()->value('SELECT COUNT(*) FROM rate_limit_hits WHERE action = ? AND key_hash = ? AND occurred_at >= ?',
            [$action, self::key($value), $since]);
        return $n >= ($max ?? $limit);
    }

    public static function hit(string $action, string $value): void
    {
        Database::get()->insert('rate_limit_hits', ['action' => $action, 'key_hash' => self::key($value), 'occurred_at' => now_utc()]);
    }

    public static function clear(string $action, string $value): void
    {
        Database::get()->run('DELETE FROM rate_limit_hits WHERE action = ? AND key_hash = ?', [$action, self::key($value)]);
    }

    public static function purge(): int
    {
        return Database::get()->run('DELETE FROM rate_limit_hits WHERE occurred_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)])->rowCount();
    }
}
