<?php
declare(strict_types=1);
// Front controller. On Hostinger, point the web root (public_html) at this directory's contents;
// everything else (src, config, storage, .env) stays above the web root.
// PHP built-in development server: let it serve existing static files directly (Apache does this via .htaccess).
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}
require dirname(__DIR__) . '/src/bootstrap.php';

$router = require APP_ROOT . '/config/routes.php';
$kernel = new App\Core\Kernel($router);
$kernel->handle(App\Core\Request::fromGlobals())->send();
