<?php
declare(strict_types=1);
// Usage: php bin/install.php owner@example.org
// Prints the one-time activation link ONCE. Pass it to the Primary Admin securely.
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$email = $argv[1] ?? '';
try {
    $r = App\Core\Installer::install(App\Core\Database::get(), $email);
    echo "Installed. Primary Admin user id {$r['owner_id']}.\n";
    echo "One-time activation link (valid 48 hours, shown once):\n";
    echo rtrim((string) env('APP_URL', 'http://localhost'), '/') . "/activate?token={$r['activation_token']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Install failed: ' . $e->getMessage() . "\n");
    exit(1);
}
