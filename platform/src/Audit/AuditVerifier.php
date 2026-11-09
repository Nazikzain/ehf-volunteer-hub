<?php
declare(strict_types=1);

namespace App\Audit;

use App\Core\Database;

/** Verification procedure (Design Rev3 §6.8). Read-only apart from the audit.verify entry. */
final class AuditVerifier
{
    /**
     * @return array{result:string, checked:int, from:?int, to:?int, problems:array, warnings:array, head:array}
     */
    public static function verify(bool $record = true): array
    {
        $db = Database::get();
        $problems = [];
        $warnings = [];
        $head = $db->one("SELECT last_seq, last_hash FROM audit_chain WHERE singleton = 'head'");
        $stmt = $db->run('SELECT * FROM audit_logs ORDER BY seq');
        $prevSeq = null;
        $prevHash = null;
        $count = 0;
        $first = null;
        $pairs = [];
        while ($r = $stmt->fetch()) {
            $count++;
            $seq = (int) $r['seq'];
            $first ??= $seq;
            if ($prevSeq === null) {
                if ($seq !== 1) {
                    $warnings[] = "Chain starts at seq {$seq} (archive checkpoint expected)";
                } elseif (bin2hex($r['prev_hash']) !== Canonical::GENESIS) {
                    $problems[] = "seq 1: genesis prev_hash is not zero";
                }
            } else {
                if ($seq !== $prevSeq + 1) {
                    $problems[] = "seq {$seq}: gap after {$prevSeq} (missing or inserted entries)";
                }
                if (!hash_equals($prevHash, $r['prev_hash'])) {
                    $problems[] = "seq {$seq}: prev_hash does not match previous row (reordered, deleted or inserted)";
                }
            }
            try {
                if ($r['details'] !== null && Canonical::recanonicaliseDetails($r['details']) !== $r['details']) {
                    $problems[] = "seq {$seq}: details not in canonical form (modified)";
                }
                if (!Keys::has((int) $r['key_id'])) {
                    $problems[] = "seq {$seq}: unknown key_id {$r['key_id']}";
                } else {
                    $calc = Keys::mac((int) $r['key_id'], Canonical::encodeRow($r));
                    if (!hash_equals($calc, $r['row_hash'])) {
                        $problems[] = "seq {$seq}: HMAC mismatch (modified or forged)";
                    }
                }
            } catch (\Throwable $e) {
                $problems[] = "seq {$seq}: cannot canonicalise row (" . $e::class . ')';
            }
            if ($r['source'] === 'fallback') {
                $warnings[] = "seq {$seq}: imported from fallback file (occurred {$r['occurred_at']})";
            }
            $pairKey = $r['request_id'] . '|' . $r['action'];
            if (isset($pairs[$pairKey]) && $r['request_id'] !== 'cli0000000000000') {
                $warnings[] = "seq {$seq}: possible duplicate of seq {$pairs[$pairKey]} (same request and action)";
            }
            $pairs[$pairKey] = $seq;
            $prevSeq = $seq;
            $prevHash = $r['row_hash'];
        }
        if ($head === null) {
            $problems[] = 'audit_chain head row missing';
        } elseif ($count > 0 && ((int) $head['last_seq'] !== $prevSeq || !hash_equals($head['last_hash'], $prevHash))) {
            $problems[] = 'Chain head does not match the last entry (head tampered or tail deleted)';
        } elseif ($count === 0 && (int) $head['last_seq'] !== 0) {
            $problems[] = 'Chain head reports entries but the log is empty';
        }
        $anchor = AuditAnchor::latest();
        if ($anchor !== null && $head !== null) {
            if ((int) $head['last_seq'] < (int) $anchor['seq']) {
                $problems[] = "Head seq {$head['last_seq']} is below anchor seq {$anchor['seq']} (truncation or rewrite)";
            } else {
                $at = $db->value('SELECT row_hash FROM audit_logs WHERE seq = ?', [(int) $anchor['seq']]);
                if ($at !== null && bin2hex($at) !== $anchor['hash']) {
                    $problems[] = "Row at anchor seq {$anchor['seq']} does not match the anchored hash";
                }
            }
        }
        $pending = count(FallbackLog::pending());
        if ($pending > 0) {
            $warnings[] = "{$pending} fallback file(s) awaiting reconciliation";
        }
        $result = $problems ? 'red' : ($pending > 0 ? 'amber' : 'green');
        $report = ['result' => $result, 'checked' => $count, 'from' => $first, 'to' => $prevSeq,
            'problems' => $problems, 'warnings' => $warnings,
            'head' => $head ? ['seq' => (int) $head['last_seq'], 'hash' => bin2hex($head['last_hash'])] : []];
        if ($record) {
            AuditLogger::record('audit.verify', $result === 'red' ? 'failed' : 'success', null, null,
                ['result' => $result, 'checked' => $count, 'problems' => count($problems)]);
        }
        return $report;
    }
}
