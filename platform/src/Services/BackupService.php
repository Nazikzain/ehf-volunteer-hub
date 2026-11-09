<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAnchor;
use App\Audit\AuditLogger;
use App\Audit\AuditVerifier;
use App\Core\Database;

/**
 * Backup and post-restore procedure (Design Rev3 §6.9, §7 W7).
 * Backup: verify audit chain -> dump database (incl. triggers) -> manifest with head, checksum, schema version.
 * Post-restore: verify -> compare with the latest OFF-DATABASE anchor -> record lost range -> re-apply erasures -> new anchor.
 */
final class BackupService
{
    public static function dumpCommand(): ?string
    {
        foreach (['mariadb-dump', 'mysqldump'] as $bin) {
            $path = trim((string) @shell_exec('command -v ' . $bin . ' 2>/dev/null'));
            if ($path !== '') {
                return $path;
            }
        }
        return null;
    }

    /** @return array manifest */
    public static function backup(string $dir): array
    {
        $verify = AuditVerifier::verify();
        if ($verify['result'] === 'red') {
            throw new \RuntimeException('Audit verification is RED. Investigate before taking a backup (the backup would preserve a tampered state).');
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $stamp = gmdate('Ymd-His');
        $file = rtrim($dir, '/') . "/ehf-backup-{$stamp}.sql";
        $bin = self::dumpCommand();
        if ($bin === null) {
            throw new \RuntimeException('mysqldump/mariadb-dump not available: use the host panel backup and run this command with --manifest-only.');
        }
        $cnf = tempnam(sys_get_temp_dir(), 'ehfcnf');
        file_put_contents($cnf, "[client]\nuser=" . env('DB_USER') . "\npassword=\"" . str_replace('"', '\\"', (string) env('DB_PASS')) . "\"\n"
            . (env('DB_SOCKET') ? 'socket=' . env('DB_SOCKET') : 'host=' . env('DB_HOST') . "\nport=" . env('DB_PORT', '3306')) . "\n");
        chmod($cnf, 0600);
        $cmd = escapeshellcmd($bin) . ' --defaults-extra-file=' . escapeshellarg($cnf) . ' --single-transaction --routines --triggers --hex-blob '
            . escapeshellarg((string) env('DB_NAME')) . ' > ' . escapeshellarg($file) . ' 2>&1';
        exec($cmd, $o, $code);
        unlink($cnf);
        if ($code !== 0 || !is_file($file) || filesize($file) < 1000) {
            throw new \RuntimeException('Database dump failed (exit ' . $code . ').');
        }
        chmod($file, 0600);
        $manifest = ['created_at' => now_utc(), 'file' => basename($file), 'sha256' => hash_file('sha256', $file), 'bytes' => filesize($file),
            'audit_head' => $verify['head'], 'audit_result' => $verify['result'], 'schema' => json_decode((string) Settings::get('schema.migrations', '[]'), true),
            'note' => 'Audit HMAC keys are NOT in this backup. They are escrowed separately (Design §6.5).'];
        file_put_contents($file . '.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        return $manifest;
    }

    /** Latest anchor manifest written to storage/exports (files survive a database restore). */
    public static function latestExternalAnchor(): ?array
    {
        $files = glob(export_dir() . '/audit-anchor-*.json') ?: [];
        sort($files);
        $f = end($files);
        return $f ? json_decode((string) file_get_contents($f), true) : null;
    }

    public static function postRestore(?array $externalAnchor = null): array
    {
        $db = Database::get();
        $externalAnchor ??= self::latestExternalAnchor();
        $pre = AuditVerifier::verify(false);
        $head = (int) ($pre['head']['seq'] ?? 0);
        $lost = null;
        if ($externalAnchor !== null && (int) $externalAnchor['seq'] > $head) {
            $lost = ['from' => $head + 1, 'to' => (int) $externalAnchor['seq']];
        }
        // Remove the stale in-database anchor so verification continues from the restored head; the evidence is kept in audit.restore.
        $db->run("DELETE FROM settings WHERE `key` = 'audit.last_anchor'");
        AuditLogger::record('audit.restore', $lost ? 'failed' : 'success', null, null, [
            'restored_head_seq' => (string) $head, 'external_anchor_seq' => $externalAnchor ? (string) $externalAnchor['seq'] : 'none',
            'external_anchor_hash' => $externalAnchor['hash'] ?? 'none', 'lost_from' => $lost ? (string) $lost['from'] : 'none',
            'lost_to' => $lost ? (string) $lost['to'] : 'none', 'pre_restore_verification' => $pre['result']]);
        // W7: re-apply erasures recorded in surviving evidence. The restored DB lacks post-backup events, so the
        // caller supplies volunteer ids from the erasure register kept off-database (exports/erasure-register.json).
        $reapplied = [];
        $register = export_dir() . '/erasure-register.json';
        if (is_file($register)) {
            foreach ((array) json_decode((string) file_get_contents($register), true) as $vid) {
                $r = ErasureService::reapplyAfterRestore((int) $vid);
                if ($r !== null) {
                    $reapplied[] = (int) $vid;
                }
            }
        }
        AuditAnchor::create();
        $post = AuditVerifier::verify();
        return ['restored_head' => $head, 'lost_range' => $lost, 'reapplied_erasures' => $reapplied, 'verification' => $post['result'], 'problems' => $post['problems']];
    }

    /** Append a volunteer id to the off-database erasure register (ids only, no personal data). */
    public static function registerErasure(int $vid): void
    {
        $f = export_dir() . '/erasure-register.json';
        $ids = is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
        $ids[] = $vid;
        file_put_contents($f, json_encode(array_values(array_unique(array_map('intval', $ids)))), LOCK_EX);
        @chmod($f, 0600);
    }
}
