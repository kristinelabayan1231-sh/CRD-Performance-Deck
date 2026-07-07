<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Normally this file lives in public/ with the app one directory up. Some
// shared hosts (e.g. InfinityFree's free tier) only expose a single web
// root folder, so deployment there flattens the whole app into that folder
// alongside this file — detect which layout we're in rather than assuming.
$basePath = is_dir(__DIR__.'/../vendor') ? __DIR__.'/..' : __DIR__;

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $basePath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $basePath.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $basePath.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
