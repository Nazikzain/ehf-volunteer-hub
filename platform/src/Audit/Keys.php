<?php
declare(strict_types=1);

namespace App\Audit;

/** Audit HMAC keys from .env (AUDIT_KEY_<id>, base64 32 bytes). Never stored in the DB or logged. */
final class Keys
{
    public static function activeId(): int
    {
        $id = (int) env('AUDIT_ACTIVE_KEY_ID', '0');
        if ($id < 1 || $id > 255) {
            throw new \RuntimeException('AUDIT_ACTIVE_KEY_ID is not configured');
        }
        return $id;
    }

    public static function get(int $id): string
    {
        $raw = env('AUDIT_KEY_' . $id);
        $key = $raw === null ? false : base64_decode($raw, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException("Audit key {$id} missing or invalid");
        }
        return $key;
    }

    public static function has(int $id): bool
    {
        try {
            self::get($id);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public static function mac(int $keyId, string $data): string
    {
        return hash_hmac('sha256', $data, self::get($keyId), true);
    }
}
