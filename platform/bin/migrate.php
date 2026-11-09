<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$db = App\Core\Database::get();
$v = App\Core\Installer::checkVersion($db);
echo "Database: {$v['engine']} {$v['version']} (" . ($v['supported'] ? 'supported' : 'NOT SUPPORTED') . ")\n";
if (!$v['supported']) { exit(1); }
$new = (new App\Core\Migrator($db))->migrate(APP_ROOT . '/database/migrations');
echo $new ? 'Applied: ' . implode(', ', $new) . "\n" : "Nothing to migrate.\n";
