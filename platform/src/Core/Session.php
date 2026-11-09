<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session wrapper. Native PHP sessions in production; an in-memory store in tests.
 * Cookies: HttpOnly, Secure (configurable), SameSite=Lax. Idle 30 min, absolute 12 h (Design §8.1).
 */
final class Session
{
    private static bool $testing = false;
    private static array $store = [];
    public const IDLE_SECONDS = 1800;
    public const ABSOLUTE_SECONDS = 43200;

    public static function useArrayStore(): void
    {
        self::$testing = true;
        self::$store = [];
    }

    public static function start(): void
    {
        if (self::$testing || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('ehf_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => env('SESSION_SECURE', 'true') === 'true',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    private static function &data(): array
    {
        if (self::$testing) {
            return self::$store;
        }
        return $_SESSION;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::data()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $d = &self::data();
        $d[$key] = $value;
    }

    public static function forget(string $key): void
    {
        $d = &self::data();
        unset($d[$key]);
    }

    public static function regenerate(): void
    {
        if (!self::$testing && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $d = &self::data();
        $d = [];
        if (!self::$testing && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function flash(string $type, string $message): void
    {
        $f = self::get('_flash', []);
        $f[] = ['type' => $type, 'message' => $message];
        self::set('_flash', $f);
    }

    public static function takeFlash(): array
    {
        $f = self::get('_flash', []);
        self::forget('_flash');
        return $f;
    }
}
