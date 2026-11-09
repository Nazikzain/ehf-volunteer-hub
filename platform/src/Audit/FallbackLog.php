<?php
declare(strict_types=1);

namespace App\Audit;

use App\Core\Database;

/**
 * Audit fallback file (Design Rev3 §6.6): JSON Lines, one file per UTC day, per-line HMAC.
 * Not an alternative log: every line must be reconciled into the chain.
 */
final class FallbackLog
{
    public static function dir(): string
    {
        $d = (string) env('AUDIT_FALLBACK_DIR', '');
        return rtrim($d !== '' ? $d : APP_ROOT . '/storage/audit-fallback', '/');
    }

    private static function payload(array $e): array
    {
        return [$e['occurred_at'], $e['actor_user_id'] === null ? null : (string) $e['actor_user_id'], $e['actor_email'],
            $e['actor_role'], $e['action'], $e['outcome'], $e['target_type'],
            $e['target_id'] === null ? null : (string) $e['target_id'],
            $e['ip'] === null ? null : bin2hex($e['ip']), $e['request_id'], $e['details']];
    }

    public static function write(array $event): void
    {
        try {
            $dir = self::dir();
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $day = gmdate('Ymd');
            $existing = glob($dir . "/fallback-{$day}-*.jsonl") ?: [];
            $file = $existing[0] ?? $dir . "/fallback-{$day}-" . bin2hex(random_bytes(8)) . '.jsonl';
            $fileId = basename($file, '.jsonl');
            $fh = fopen($file, 'ab');
            if ($fh === false) {
                throw new \RuntimeException('cannot open fallback file');
            }
            @chmod($file, 0600);
            try {
                if (!flock($fh, LOCK_EX)) {
                    throw new \RuntimeException('cannot lock fallback file');
                }
                clearstatcache(true, $file);
                $lineNo = 1 + (filesize($file) > 0 ? count(file($file)) : 0);
                $keyId = Keys::activeId();
                $payload = self::payload($event);
                $mac = bin2hex(Keys::mac($keyId, $fileId . '|' . $lineNo . '|' . json_encode($payload, Canonical::FLAGS)));
                $line = json_encode(['v' => 1, 'file_id' => $fileId, 'line' => $lineNo, 'key_id' => $keyId,
                        'event' => $payload, 'mac' => $mac], Canonical::FLAGS) . "\n";
                $n = fwrite($fh, $line);
                fflush($fh);
                if (function_exists('fsync')) {
                    fsync($fh);
                }
                if ($n !== strlen($line)) {
                    throw new \RuntimeException('short write to fallback file');
                }
            } finally {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        } catch (\Throwable $t) {
            // Last resort (Design §6.3, residual risk U-6): minimal, non-personal line to the PHP error log.
            error_log(sprintf('EHF-AUDIT-FALLBACK-FAILED time=%s action=%s outcome=%s request=%s',
                $event['occurred_at'] ?? '-', $event['action'] ?? '-', $event['outcome'] ?? '-', $event['request_id'] ?? '-'));
        }
    }

    /** Unreconciled files waiting in the directory. */
    public static function pending(): array
    {
        return glob(self::dir() . '/fallback-*.jsonl') ?: [];
    }

    /**
     * Reconcile all pending files into the chain. Idempotent via UNIQUE(fallback_ref).
     * @return array{imported:int, rejected:int, files:int}
     */
    public static function reconcile(): array
    {
        $db = Database::get();
        $stats = ['imported' => 0, 'rejected' => 0, 'files' => 0];
        $files = self::pending();
        sort($files);
        foreach ($files as $file) {
            $fileId = basename($file, '.jsonl');
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            $expected = 1;
            $rejectedHere = 0;
            foreach ($lines as $i => $raw) {
                $reason = null;
                $rec = json_decode($raw, true);
                if (!is_array($rec) || ($rec['v'] ?? null) !== 1 || !isset($rec['event'], $rec['mac'], $rec['line'], $rec['key_id'])) {
                    $reason = 'malformed';
                } elseif ($rec['file_id'] !== $fileId) {
                    $reason = 'file_id_mismatch';
                } elseif (!Keys::has((int) $rec['key_id'])) {
                    $reason = 'unknown_key';
                } else {
                    $calc = bin2hex(Keys::mac((int) $rec['key_id'], $fileId . '|' . $rec['line'] . '|' . json_encode($rec['event'], Canonical::FLAGS)));
                    if (!hash_equals($calc, (string) $rec['mac'])) {
                        $reason = 'bad_mac';
                    }
                }
                $lineNo = is_array($rec) && isset($rec['line']) ? (int) $rec['line'] : $i + 1;
                if ($reason === null && $lineNo !== $expected) {
                    AuditLogger::record('audit.fallback_gap', 'failed', null, null, ['file' => $fileId, 'expected' => $expected, 'found' => $lineNo]);
                }
                $expected = $lineNo + 1;
                if ($reason !== null) {
                    $rejectedHere++;
                    $stats['rejected']++;
                    AuditLogger::record('audit.fallback_line_rejected', 'failed', null, null, ['file' => $fileId, 'line' => $lineNo, 'reason' => $reason]);
                    continue;
                }
                $ref = hash('sha256', $fileId . ':' . $lineNo, true);
                [$occ, $uid, $email, $role, $action, $outcome, $tt, $tid, $ipHex, $req, $details] = $rec['event'];
                $event = ['occurred_at' => $occ, 'actor_user_id' => $uid === null ? null : (int) $uid, 'actor_email' => $email,
                    'actor_role' => $role, 'action' => $action, 'outcome' => $outcome, 'target_type' => $tt,
                    'target_id' => $tid === null ? null : (int) $tid, 'ip' => $ipHex === null ? null : hex2bin($ipHex),
                    'request_id' => $req, 'details' => $details];
                $db->transaction(function (Database $d) use ($event, $ref, &$stats) {
                    if ($d->value('SELECT 1 FROM audit_logs WHERE fallback_ref = ?', [$ref])) {
                        return; // already imported (idempotent)
                    }
                    AuditLogger::append($d, $event, 'fallback', $ref);
                    $stats['imported']++;
                });
            }
            $sub = $rejectedHere > 0 ? 'quarantine' : 'reconciled';
            if (!is_dir(self::dir() . '/' . $sub)) {
                mkdir(self::dir() . '/' . $sub, 0700, true);
            }
            AuditLogger::record('audit.fallback_file_reconciled', $rejectedHere > 0 ? 'failed' : 'success', null, null,
                ['file' => $fileId, 'sha256' => hash_file('sha256', $file), 'lines' => count($lines), 'rejected' => $rejectedHere]);
            rename($file, self::dir() . "/{$sub}/" . basename($file));
            $stats['files']++;
        }
        return $stats;
    }
}
