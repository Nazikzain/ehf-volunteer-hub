<?php
declare(strict_types=1);
// Usage: php bin/backup.php /path/outside/web/root   (verifies the audit chain, dumps the DB incl. triggers, writes a manifest)
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
try {
    $m = App\Services\BackupService::backup($argv[1] ?? APP_ROOT . '/storage/backups');
    echo json_encode($m, JSON_PRETTY_PRINT), "\nStore the .sql and .manifest.json OFF this server. Keys are not included.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
