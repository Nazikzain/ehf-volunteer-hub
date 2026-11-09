<?php
declare(strict_types=1);

namespace App\Core;

/** Safe error handling: details go to storage/logs, users see a generic page. */
final class ErrorHandler
{
    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
    }

    public static function log(\Throwable $e, string $requestId = '-'): void
    {
        $line = sprintf("[%s] [%s] %s: %s in %s:%d\n", now_utc(), $requestId, $e::class,
            self::redact($e->getMessage()), $e->getFile(), $e->getLine());
        @file_put_contents(APP_ROOT . '/storage/logs/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** Strip anything that looks like a credential before logging. */
    public static function redact(string $s): string
    {
        return (string) preg_replace('/(password|passwd|pwd|token|secret|key)\s*[=:]\s*\S+/i', '$1=[redacted]', $s);
    }
}
