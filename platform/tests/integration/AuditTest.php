<?php
declare(strict_types=1);

use App\Audit\AuditAnchor;
use App\Audit\AuditLogger;
use App\Audit\AuditVerifier;
use App\Audit\FallbackLog;
use App\Core\Env;
use Tests\T;

T::test('audit', 'chain verifies green; seq gap-free even when a business transaction rolls back', function () {
    $db = T::db();
    $before = (int) $db->value("SELECT last_seq FROM audit_chain");
    try {
        $db->transaction(function () {
            AuditLogger::record('project.create', 'success', 'project', 1);
            throw new RuntimeException('business failure');
        });
    } catch (RuntimeException) {
    }
    T::eq($before, (int) $db->value("SELECT last_seq FROM audit_chain"), 'rolled-back entry consumed no seq');
    AuditLogger::record('settings.update', 'success', null, null, ['key' => 'x']);
    $r = AuditVerifier::verify(false);
    T::eq('green', $r['result'], implode('; ', $r['problems']));
    T::eq($r['checked'], $r['to'], 'seq 1..n contiguous');
});

T::test('audit', 'staff actor email recorded; volunteer actor email never recorded (R3-10)', function () {
    $vol = T::user('actor.vol@example.test', ['volunteer']);
    T::actingAs($vol);
    App\Security\Auth::loadFromSession();
    AuditLogger::record('volunteer.update', 'success', 'volunteer', 1, ['fields' => ['phone']]);
    T::eq(null, T::db()->value("SELECT actor_email FROM audit_logs WHERE actor_user_id = ? ORDER BY seq DESC LIMIT 1", [$vol]));
    $adm = T::user('actor.adm@example.test', ['organisation_admin']);
    T::actingAs($adm);
    App\Security\Auth::loadFromSession();
    AuditLogger::record('project.update', 'success', 'project', 1);
    T::eq('actor.adm@example.test', T::db()->value("SELECT actor_email FROM audit_logs WHERE actor_user_id = ? ORDER BY seq DESC LIMIT 1", [$adm]));
});

T::test('audit', 'unknown action codes are rejected', function () {
    T::throws(InvalidArgumentException::class, fn() => AuditLogger::record('made.up'));
});

T::test('audit', 'tampering detected: modified row, deleted row (triggers bypassed with DDL as an attacker would)', function () {
    $db = T::db();
    AuditLogger::record('project.create', 'success', 'project', 99);
    $db->pdo->exec('DROP TRIGGER trg_audit_logs_no_update');
    $db->pdo->exec('DROP TRIGGER trg_audit_logs_no_delete');
    try {
        $seq = (int) $db->value('SELECT MAX(seq) FROM audit_logs') - 1;
        $db->run("UPDATE audit_logs SET target_id = 12345 WHERE seq = ?", [$seq]);
        $r = AuditVerifier::verify(false);
        T::eq('red', $r['result']);
        T::assert((bool) preg_grep('/HMAC mismatch/', $r['problems']), 'modification detected');
        $db->run("UPDATE audit_logs SET target_id = NULL WHERE seq = ?", [$seq]); // still wrong value: keep red
        $db->run("DELETE FROM audit_logs WHERE seq = ?", [$seq - 1]);
        $r2 = AuditVerifier::verify(false);
        T::assert((bool) preg_grep('/gap after/', $r2['problems']), 'deletion detected');
    } finally {
        // restore a clean, verifiable state for later tests: rebuild the schema
        T::setUpDatabase();
    }
});

T::test('audit', 'tail truncation detected against the external anchor', function () {
    $db = T::db();
    AuditLogger::record('project.create', 'success', 'project', 1);
    AuditAnchor::create();
    $anchorSeq = (int) json_decode((string) $db->value("SELECT value FROM settings WHERE `key` = 'audit.last_anchor'"), true)['seq'];
    $db->pdo->exec('DROP TRIGGER trg_audit_logs_no_delete');
    try {
        $db->run('DELETE FROM audit_logs WHERE seq >= ?', [$anchorSeq - 1]);
        $last = $db->one('SELECT seq, row_hash FROM audit_logs ORDER BY seq DESC LIMIT 1');
        $db->run("UPDATE audit_chain SET last_seq = ?, last_hash = ?", [$last['seq'], $last['row_hash']]); // attacker rewrites head
        $r = AuditVerifier::verify(false);
        T::eq('red', $r['result']);
        T::assert((bool) preg_grep('/below anchor/', $r['problems']), 'truncation detected via anchor');
    } finally {
        T::setUpDatabase();
    }
});

T::test('audit', 'fallback: DB failure writes MAC-protected line; reconcile imports once; tampered line quarantined', function () {
    $dir = sys_get_temp_dir() . '/ehf-fallback-test-' . bin2hex(random_bytes(4));
    Env::set('AUDIT_FALLBACK_DIR', $dir);
    try {
        $db = T::db();
        $db->pdo->exec('RENAME TABLE audit_chain TO audit_chain_offline'); // simulate audit table failure
        AuditLogger::record('access.denied', 'denied', null, null, ['route' => 'GET /x']);
        AuditLogger::record('access.denied', 'denied', null, null, ['route' => 'GET /y']);
        $db->pdo->exec('RENAME TABLE audit_chain_offline TO audit_chain');
        $files = FallbackLog::pending();
        T::eq(1, count($files));
        T::eq(2, count(file($files[0])));
        $verify = AuditVerifier::verify(false);
        T::eq('amber', $verify['result'], 'pending fallback reported amber');
        $stats = FallbackLog::reconcile();
        T::eq(2, $stats['imported']);
        T::eq(2, (int) $db->value("SELECT COUNT(*) FROM audit_logs WHERE source = 'fallback'"));
        T::eq('green', AuditVerifier::verify(false)['result']);
        // Tampered line: change content without the key -> rejected and quarantined
        $db->pdo->exec('RENAME TABLE audit_chain TO audit_chain_offline');
        AuditLogger::record('access.denied', 'denied', null, null, ['route' => 'GET /z']);
        $db->pdo->exec('RENAME TABLE audit_chain_offline TO audit_chain');
        $f = FallbackLog::pending()[0];
        file_put_contents($f, str_replace('GET /z', 'GET /changed', (string) file_get_contents($f)));
        $s2 = FallbackLog::reconcile();
        T::eq(1, $s2['rejected']);
        T::eq(1, count(glob($dir . '/quarantine/*.jsonl')));
        T::assert((bool) $db->value("SELECT 1 FROM audit_logs WHERE action = 'audit.fallback_line_rejected'"), 'rejection audited');
    } finally {
        Env::set('AUDIT_FALLBACK_DIR', '');
        exec('rm -rf ' . escapeshellarg($dir));
        T::setUpDatabase();
    }
});

T::test('audit', 'denied requests are audited even though the business transaction rolled back', function () {
    $vol = T::user('deny.vol@example.test', ['volunteer']);
    T::actingAs($vol);
    T::eq(403, T::get('/admin/users')->status);
    T::assert((bool) T::db()->value("SELECT 1 FROM audit_logs WHERE action = 'access.denied' AND actor_user_id = ? AND outcome = 'denied'", [$vol]));
});
