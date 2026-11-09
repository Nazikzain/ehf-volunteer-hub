<?php
declare(strict_types=1);

use App\Services\BackupService;
use Tests\T;

T::test('backup', 'backup -> later changes -> restore -> lost audit range recorded, erasure re-applied, chain green', function () {
    $db = T::db();
    $dir = sys_get_temp_dir() . '/ehf-backup-test-' . bin2hex(random_bytes(4));
    $register = export_dir() . '/erasure-register.json';
    $savedRegister = is_file($register) ? file_get_contents($register) : null;
    @unlink($register);
    try {
        $vid = T::volunteer('Backup Person');
        $db->run("UPDATE volunteers SET phone = '07700 900999' WHERE id = ?", [$vid]);
        $m = BackupService::backup($dir);
        T::assert(is_file($dir . '/' . $m['file']) && $m['sha256'] === hash_file('sha256', $dir . '/' . $m['file']), 'dump + checksum');
        T::assert(str_contains((string) file_get_contents($dir . '/' . $m['file']), 'trg_audit_logs_no_update'), 'triggers included in dump');
        // after the backup: more audit events, an anchor, and an approved erasure
        $oa = T::user('bk.oa@example.test', ['organisation_admin']);
        T::actingAs($oa);
        T::post("/volunteers/{$vid}/erasure", ['action' => 'prepare', 'request_ref' => 'Synthetic backup test']);
        T::actingAs(T::$ownerId);
        T::post("/volunteers/{$vid}/erasure", ['action' => 'approve', 'confirm1' => '1', 'confirm2' => '1']);
        T::assert($db->value('SELECT anonymised_at FROM volunteers WHERE id = ?', [$vid]) !== null, 'erased before restore');
        App\Audit\AuditAnchor::create();
        $anchorSeq = (int) BackupService::latestExternalAnchor()['seq'];
        // restore the backup (drop + recreate + import), as an operator would
        $name = (string) env('DB_NAME');
        $db->pdo->exec("DROP DATABASE `{$name}`");
        $db->pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->pdo->exec("USE `{$name}`");
        $cnf = tempnam(sys_get_temp_dir(), 'c');
        file_put_contents($cnf, "[client]\nuser=" . env('DB_USER') . "\npassword=\"" . env('DB_PASS') . "\"\nhost=" . env('DB_HOST') . "\n");
        exec('mariadb --defaults-extra-file=' . escapeshellarg($cnf) . ' ' . escapeshellarg($name) . ' < ' . escapeshellarg($dir . '/' . $m['file']), $o, $code);
        unlink($cnf);
        T::eq(0, $code, 'import');
        T::eq('07700 900999', $db->value('SELECT phone FROM volunteers WHERE id = ?', [$vid]), 'restored data predates the erasure');
        $r = BackupService::postRestore();
        T::assert($r['lost_range'] !== null && $r['lost_range']['to'] === $anchorSeq, 'lost audit range detected via off-database anchor');
        T::eq([$vid], $r['reapplied_erasures'], 'erasure re-applied');
        T::eq(null, $db->value('SELECT phone FROM volunteers WHERE id = ?', [$vid]), 'personal data erased again');
        T::eq('green', $r['verification'], implode('; ', $r['problems']));
        T::assert((bool) $db->value("SELECT 1 FROM audit_logs WHERE action = 'audit.restore' AND outcome = 'failed'"), 'restore with loss recorded');
    } finally {
        exec('rm -rf ' . escapeshellarg($dir));
        $savedRegister === null ? @unlink($register) : file_put_contents($register, $savedRegister);
        foreach (glob(export_dir() . '/audit-anchor-*.json') ?: [] as $f) { @unlink($f); }
        T::setUpDatabase();
    }
});
