<?php
declare(strict_types=1);

namespace App\Audit;

/**
 * Canonical representation v1 (Design Review Rev3 §6.4).
 * An 18-element JSON array, UTF-8, integers as decimal strings, UTC timestamps with 6 fractional
 * digits, binary as lower-case hex, details with recursively sorted keys and no JSON numbers.
 */
final class Canonical
{
    public const VERSION = 1;
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Normalise a details array: ints/floats -> strings, keys sorted recursively. Empty -> null. */
    public static function normaliseDetails(?array $details): ?array
    {
        if ($details === null || $details === []) {
            return null;
        }
        return self::norm($details);
    }

    private static function norm(array $a): array
    {
        $isList = array_is_list($a);
        $out = [];
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $v = self::norm($v);
            } elseif (is_int($v) || is_float($v)) {
                $v = (string) $v;
            } elseif (!is_string($v) && !is_bool($v) && $v !== null) {
                throw new \InvalidArgumentException('Unsupported value type in audit details');
            }
            $out[$isList ? count($out) : (string) $k] = $v;
        }
        if (!$isList) {
            ksort($out, SORT_STRING); // byte order of UTF-8 == Unicode code-point order
        }
        return $out;
    }

    /** Canonical text stored in audit_logs.details. */
    public static function detailsText(?array $normalised): ?string
    {
        if ($normalised === null) {
            return null;
        }
        // Force JSON objects for associative arrays, including nested ones.
        return json_encode(self::objectify($normalised), self::FLAGS);
    }

    private static function objectify(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        $mapped = array_map([self::class, 'objectify'], $v);
        return array_is_list($v) ? $mapped : (object) $mapped;
    }

    public static function intStr(int|string|null $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', (string) $v)) {
            throw new \InvalidArgumentException('Non-integer value');
        }
        return ltrim((string) $v, '0') === '' ? '0' : ltrim((string) $v, '0');
    }

    /** 'Y-m-d H:i:s.u' (UTC) -> 'Y-m-d\TH:i:s.u\Z' */
    public static function ts(string $dbValue): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $dbValue, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dbValue, new \DateTimeZone('UTC'));
        if (!$dt) {
            throw new \InvalidArgumentException('Bad timestamp');
        }
        return $dt->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function ip(?string $binary): ?string
    {
        if ($binary === null || $binary === '') {
            return null;
        }
        $t = inet_ntop($binary);
        return $t === false ? null : strtolower($t);
    }

    /**
     * Build canonical bytes from a row as stored (binary columns raw, details as stored text).
     */
    public static function encodeRow(array $r): string
    {
        $details = $r['details'] === null ? null : json_decode((string) $r['details'], false, 512, JSON_THROW_ON_ERROR);
        $arr = [
            self::intStr($r['canon_version']),
            self::intStr($r['seq']),
            self::ts((string) $r['occurred_at']),
            self::ts((string) $r['recorded_at']),
            (string) $r['source'],
            $r['fallback_ref'] === null ? null : bin2hex((string) $r['fallback_ref']),
            self::intStr($r['actor_user_id']),
            $r['actor_email'],
            $r['actor_role'],
            (string) $r['action'],
            (string) $r['outcome'],
            $r['target_type'],
            self::intStr($r['target_id']),
            self::ip($r['ip']),
            (string) $r['request_id'],
            $details,
            self::intStr($r['key_id']),
            bin2hex((string) $r['prev_hash']),
        ];
        return json_encode($arr, self::FLAGS);
    }

    /** Re-canonicalise stored details text; must equal the stored text byte for byte. */
    public static function recanonicaliseDetails(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('details must be an object');
        }
        self::assertNoNumbers($decoded);
        return self::detailsText(self::norm($decoded));
    }

    private static function assertNoNumbers(array $a): void
    {
        foreach ($a as $v) {
            if (is_int($v) || is_float($v)) {
                throw new \InvalidArgumentException('JSON numbers are not permitted in details');
            }
            if (is_array($v)) {
                self::assertNoNumbers($v);
            }
        }
    }
}
