<?php
declare(strict_types=1);
// Front controller. On Hostinger, point the web root (public_html) at this directory's contents;
// everything else (src, config, storage, .env) stays above the web root.
require dirname(__DIR__) . '/src/bootstrap.php';

$router = require APP_ROOT . '/config/routes.php';
$kernel = new App\Core\Kernel($router);
$kernel->handle(App\Core\Request::fromGlobals())->send();
