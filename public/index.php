<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
 * An API answers in JSON or it answers in nothing.
 *
 * PHP writes notices straight to the output stream, ahead of anything the
 * framework produces, so a single deprecation turns every response into
 * `<b>Deprecated</b>: ...{"success":true,...}` — valid to no parser alive. On
 * PHP 8.5 that is not hypothetical: Laravel 11's own `config/database.php`
 * still names `PDO::MYSQL_ATTR_SSL_CA`, and it is evaluated on every request
 * before a line of application code runs, so there is nowhere later to fix it
 * and the vendor file is not ours to edit.
 *
 * Nothing is lost. Errors still reach `storage/logs`, and the debug page
 * renders exceptions itself rather than through `display_errors`. This is a
 * web entry point only — Artisan keeps its own reporting, which is where a
 * deprecation is worth reading.
 */
ini_set('display_errors', '0');

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
