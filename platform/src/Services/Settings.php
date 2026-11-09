<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class Settings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            $v = Database::get()->value('SELECT value FROM settings WHERE `key` = ?', [$key]);
        } catch (\Throwable) {
            return $default;
        }
        return $v === null ? $default : (string) $v;
    }

    public static function set(string $key, string $value, ?int $by): void
    {
        Database::get()->run('INSERT INTO settings (`key`, value, updated_by, updated_at) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$key, $value, $by, now_utc()]);
    }
}
