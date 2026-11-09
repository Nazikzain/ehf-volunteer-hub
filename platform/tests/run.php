<?php
declare(strict_types=1);
// Usage: php tests/run.php [filter]   (rebuilds the ehf_test database; synthetic data only)
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/Harness.php';
foreach (array_merge(glob(__DIR__ . '/unit/*.php'), glob(__DIR__ . '/integration/*.php')) as $f) {
    require $f;
}
Tests\T::setUpDatabase();
$fail = Tests\T::run($argv[1] ?? null);
// Machine-readable results for the test report.
@mkdir(__DIR__ . '/output', 0700, true);
file_put_contents(__DIR__ . '/output/results.json', json_encode(['run_at' => now_utc(), 'php' => PHP_VERSION,
    'db' => App\Core\Database::get()->value('SELECT VERSION()'), 'results' => Tests\T::$results], JSON_PRETTY_PRINT));
exit($fail > 0 ? 1 : 0);
