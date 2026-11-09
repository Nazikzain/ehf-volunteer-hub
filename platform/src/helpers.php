<?php
declare(strict_types=1);

/** HTML-escape for output (XSS protection). */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function env(string $key, ?string $default = null): ?string
{
    return App\Core\Env::get($key, $default);
}

/** Current UTC time as 'Y-m-d H:i:s' (or with microseconds). */
function now_utc(bool $micro = false): string
{
    $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $micro ? $dt->format('Y-m-d H:i:s.u') : $dt->format('Y-m-d H:i:s');
}

/** Today's date in the organisation's local time zone (activity dates are local, Design §3.2). */
function today_local(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(env('APP_TIMEZONE', 'Europe/London'))))->format('Y-m-d');
}

/** Format a UTC timestamp for display in local time. */
function local_dt(?string $utc, string $format = 'd M Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '—';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone(env('APP_TIMEZONE', 'Europe/London')))->format($format);
}

function fmt_date(?string $date): string
{
    return $date ? (new DateTimeImmutable($date))->format('d M Y') : '—';
}

function url(string $path = '/', array $query = []): string
{
    $base = rtrim((string) env('APP_BASE_PATH', ''), '/');
    $q = $query ? '?' . http_build_query($query) : '';
    return $base . $path . $q;
}

function minutes_to_hours(int|float|string|null $minutes): string
{
    return number_format(((float) $minutes) / 60, 1);
}

function label(string $code): string
{
    return ucfirst(str_replace('_', ' ', $code));
}

/** Directory for anchors, the erasure register and other off-database evidence (configurable; outside the web root). */
function export_dir(): string
{
    $d = (string) env('EXPORT_DIR', '');
    $d = $d !== '' ? $d : APP_ROOT . '/storage/exports';
    if (!is_dir($d)) {
        mkdir($d, 0700, true);
    }
    return rtrim($d, '/');
}
