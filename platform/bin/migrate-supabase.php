<?php
declare(strict_types=1);
/*
 * Usage: php bin/migrate-supabase.php <csv-export-dir> <classification.json> [--apply]
 * Dry run by default. Requires the classification file signed off by EHF (see docs/MIGRATION.md).
 * Run on XAMPP against a COPY first; production only with explicit EHF authorisation of the cutover.
 */
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
[$dir, $class] = [$argv[1] ?? '', $argv[2] ?? ''];
$apply = in_array('--apply', $argv, true);
if (!is_dir($dir) || !is_file($class)) { fwrite(STDERR, "Usage: php bin/migrate-supabase.php <csv-dir> <classification.json> [--apply]\n"); exit(1); }
$c = json_decode((string) file_get_contents($class), true);
if (empty($c['approved_by']) || empty($c['approved_on'])) { fwrite(STDERR, "Classification file must record approved_by and approved_on.\n"); exit(1); }
$owner = (int) App\Core\Database::get()->value('SELECT user_id FROM system_owner');
try {
    $r = App\Services\SupabaseImporter::run($dir, $c, $apply, $owner);
    echo json_encode($r, JSON_PRETTY_PRINT), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
