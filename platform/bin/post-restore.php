<?php
declare(strict_types=1);
// Run immediately after restoring a database backup, BEFORE reopening the system to users (Design §6.9).
// Optional: php bin/post-restore.php <anchor-seq> <anchor-hash>  (from the latest daily anchor email if it is newer than the files)
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$anchor = isset($argv[1], $argv[2]) ? ['seq' => (int) $argv[1], 'hash' => $argv[2]] : null;
$r = App\Services\BackupService::postRestore($anchor);
echo json_encode($r, JSON_PRETTY_PRINT), "\n";
exit($r['verification'] === 'red' ? 1 : 0);
