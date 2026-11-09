<?php
declare(strict_types=1);
/*
 * Hosting compatibility probe (Design Rev3 §9). Run on the target host BEFORE installing:
 *   php bin/check-environment.php
 * It tests each required database feature for real (it does not trust version numbers alone),
 * using temporary tables named zz_ehf_probe_* that are dropped afterwards. No application data is touched.
 * Exit code 0 = all mandatory checks passed.
 */
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }

use App\Core\Database;

$results = [];
function check(string $name, bool $mandatory, callable $fn): void {
    global $results;
    try { [$ok, $info] = $fn(); } catch (Throwable $e) { $ok = false; $info = $e::class . ': ' . substr($e->getMessage(), 0, 150); }
    $results[] = [$name, $mandatory, $ok, $info];
}
function expectFail(PDO $pdo, string $sql): bool {
    try { $pdo->exec($sql); return false; } catch (PDOException) { return true; }
}

check('PHP >= 8.2', true, fn() => [PHP_VERSION_ID >= 80200, PHP_VERSION]);
foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'hash', 'session', 'intl'] as $ext) {
    check("PHP extension {$ext}", $ext !== 'intl', fn() => [extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing']);
}
check('PHP random_bytes / hash_hmac / hash_equals', true, fn() => [function_exists('random_bytes') && function_exists('hash_hmac') && function_exists('hash_equals'), 'ok']);
check('PHP fsync() (audit fallback durability)', false, fn() => [function_exists('fsync'), function_exists('fsync') ? 'available' : 'missing: fflush only']);
check('storage/ writable', true, fn() => [is_writable(APP_ROOT . '/storage/logs') && is_writable(APP_ROOT . '/storage/audit-fallback'), APP_ROOT . '/storage']);

$db = Database::get();
$pdo = $db->pdo;
$v = App\Core\Installer::checkVersion($db);
check('Database version floor (MySQL >= 8.0.16 / MariaDB >= 10.6)', true, fn() => [$v['supported'], "{$v['engine']} {$v['version']}"]);
check('InnoDB available', true, function () use ($db) {
    $r = $db->one("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'");
    return [$r && in_array($r['SUPPORT'], ['YES', 'DEFAULT'], true), $r['SUPPORT'] ?? 'absent'];
});

$p = 'zz_ehf_probe_';
$drop = function () use ($pdo, $p) {
    foreach (['trg_x'] as $t) { try { $pdo->exec("DROP TRIGGER IF EXISTS {$p}{$t}"); } catch (PDOException) {} }
    foreach (['child', 'parent', 'chk', 'gen', 'ts', 'trg'] as $t) { try { $pdo->exec("DROP TABLE IF EXISTS {$p}{$t}"); } catch (PDOException) {} }
};
$drop();
try {
    check('Composite foreign key enforced (InnoDB)', true, function () use ($pdo, $p) {
        $pdo->exec("CREATE TABLE {$p}parent (id INT, pid INT, PRIMARY KEY (id), UNIQUE KEY (id, pid)) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE {$p}child (id INT PRIMARY KEY, a INT, b INT NULL, FOREIGN KEY (a, b) REFERENCES {$p}parent (id, pid) ON DELETE RESTRICT) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO {$p}parent VALUES (1, 10)");
        $pdo->exec("INSERT INTO {$p}child VALUES (1, 1, 10)");
        $bad = expectFail($pdo, "INSERT INTO {$p}child VALUES (2, 1, 11)");
        $pdo->exec("INSERT INTO {$p}child VALUES (3, 99, NULL)"); // NULL => not checked (MATCH SIMPLE)
        $restrict = expectFail($pdo, "DELETE FROM {$p}parent WHERE id = 1");
        return [$bad && $restrict, 'violating insert and parent delete rejected; NULL component unchecked'];
    });
    check('CHECK constraints enforced', true, function () use ($pdo, $p) {
        $pdo->exec("CREATE TABLE {$p}chk (n INT, CONSTRAINT c1 CHECK (n BETWEEN 1 AND 5)) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO {$p}chk VALUES (3)");
        return [expectFail($pdo, "INSERT INTO {$p}chk VALUES (9)"), 'out-of-range insert rejected'];
    });
    check('JSON_VALID() inside CHECK', true, function () use ($pdo, $p) {
        $pdo->exec("ALTER TABLE {$p}chk ADD COLUMN d TEXT NULL, ADD CONSTRAINT c2 CHECK (d IS NULL OR JSON_VALID(d))");
        $pdo->exec("INSERT INTO {$p}chk VALUES (2, '{\"a\":\"b\"}')");
        return [expectFail($pdo, "INSERT INTO {$p}chk VALUES (2, '{bad')"), 'invalid JSON rejected'];
    });
    check('Stored generated column + UNIQUE index', false, function () use ($pdo, $p) {
        $pdo->exec("CREATE TABLE {$p}gen (id INT PRIMARY KEY, st VARCHAR(10), em VARCHAR(50),
            oe VARCHAR(50) GENERATED ALWAYS AS (CASE WHEN st = 'open' THEN em END) STORED, UNIQUE KEY (oe)) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO {$p}gen (id, st, em) VALUES (1, 'open', 'a@x'), (2, 'closed', 'a@x'), (3, 'closed', 'a@x')");
        return [expectFail($pdo, "INSERT INTO {$p}gen (id, st, em) VALUES (4, 'open', 'a@x')"), 'second open row rejected; closed rows allowed'];
    });
    check('DATETIME(6) microsecond round trip', true, function () use ($pdo, $p, $db) {
        $pdo->exec("CREATE TABLE {$p}ts (t DATETIME(6)) ENGINE=InnoDB");
        $pdo->exec("INSERT INTO {$p}ts VALUES ('2026-01-02 03:04:05.123456')");
        $t = (string) $db->value("SELECT t FROM {$p}ts");
        return [$t === '2026-01-02 03:04:05.123456', $t];
    });
    check('Triggers with SIGNAL (audit protection layer 2)', false, function () use ($pdo, $p) {
        $pdo->exec("CREATE TABLE {$p}trg (id INT PRIMARY KEY) ENGINE=InnoDB");
        $pdo->exec("CREATE TRIGGER {$p}trg_x BEFORE DELETE ON {$p}trg FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'");
        $pdo->exec("INSERT INTO {$p}trg VALUES (1)");
        return [expectFail($pdo, "DELETE FROM {$p}trg WHERE id = 1"), 'delete blocked by trigger'];
    });
    check('Transactions + SELECT ... FOR UPDATE', true, function () use ($pdo, $p) {
        $pdo->beginTransaction();
        $pdo->query("SELECT * FROM {$p}parent WHERE id = 1 FOR UPDATE")->fetchAll();
        $pdo->rollBack();
        return [true, 'ok'];
    });
} finally {
    $drop();
}
check('Commit durability (innodb_flush_log_at_trx_commit = 1)', false, fn() => [(string) $db->value('SELECT @@innodb_flush_log_at_trx_commit') === '1', (string) $db->value('SELECT @@innodb_flush_log_at_trx_commit')]);
check('Per-table privilege model (informational)', false, function () use ($db) {
    $g = array_map(fn($r) => array_values($r)[0], $db->all('SHOW GRANTS FOR CURRENT_USER()'));
    $all = (bool) preg_grep('/ALL PRIVILEGES/i', $g);
    return [!$all, $all ? 'account has ALL PRIVILEGES (no per-table restriction; residual risk U-1 applies)' : 'restricted grants present'];
});

$failMandatory = false;
printf("%-58s %-10s %-6s %s\n", 'Check', 'Required', 'Result', 'Detail');
foreach ($results as [$n, $m, $ok, $info]) {
    printf("%-58s %-10s %-6s %s\n", $n, $m ? 'mandatory' : 'preferred', $ok ? 'PASS' : 'FAIL', $info);
    if ($m && !$ok) { $failMandatory = true; }
}
echo $failMandatory ? "\nRESULT: this host does NOT meet the mandatory requirements. Do not install for production.\n"
                    : "\nRESULT: all mandatory checks passed.\n";
exit($failMandatory ? 1 : 0);
