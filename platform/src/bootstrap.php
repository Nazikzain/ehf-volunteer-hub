<?php
declare(strict_types=1);

// EHF Volunteer Hub bootstrap: autoloading, environment, error handling, UTC clock.
define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/src/helpers.php';

App\Core\Env::load(getenv('EHF_ENV_FILE') ?: APP_ROOT . '/.env');
date_default_timezone_set('UTC'); // All event timestamps are UTC (Design §3.2).
mb_internal_encoding('UTF-8');
App\Core\ErrorHandler::register();
