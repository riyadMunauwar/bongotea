<?php

use App\Support\PageCache\FallbackPageCacheReader;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Serve a cached landing page ourselves if the webserver did not...
require_once __DIR__.'/../app/Support/PageCache/FallbackPageCacheReader.php';
(new FallbackPageCacheReader(__DIR__.'/page-cache'))->serve($_SERVER);

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
