<?php

// Install this file as sena_reflection/index.php, beside the laravel directory.
// Keep SCRIPT_NAME and SCRIPT_FILENAME pointing at this public entry point so
// Laravel detects /sena_reflection as the request base URL.
$laravelEntryPoint = __DIR__.'/laravel/public/index.php';

if (! is_file($laravelEntryPoint) || ! is_file(__DIR__.'/laravel/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'The application is being prepared. Please try again shortly.';
    exit;
}

require $laravelEntryPoint;
