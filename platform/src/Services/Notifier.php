<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** In-app notifications. They work without email (Design §5 F2). */
final class Notifier
{
    public static function notify(int $userId, string $type, string $title, ?string $body = null, ?string $linkPath = null): void
    {
        if ($linkPath !== null && (!str_starts_with($linkPath, '/') || str_starts_with($linkPath, '//'))) {
            throw new \InvalidArgumentException('link_path must be a relative path');
        }
        Database::get()->insert('notifications', ['user_id' => $userId, 'type' => $type, 'title' => mb_substr($title, 0, 200),
            'body' => $body === null ? null : mb_substr($body, 0, 500), 'link_path' => $linkPath, 'created_at' => now_utc()]);
    }

    public static function notifyAdmins(string $type, string $title, ?string $body = null, ?string $link = null): void
    {
        $ids = Database::get()->all("SELECT DISTINCT u.id FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id LEFT JOIN system_owner s ON s.user_id = u.id
            WHERE u.status = 'active' AND (r.code = 'organisation_admin' OR s.user_id IS NOT NULL)");
        foreach ($ids as $r) {
            self::notify((int) $r['id'], $type, $title, $body, $link);
        }
    }
}
