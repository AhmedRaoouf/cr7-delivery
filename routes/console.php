<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Telescope\Telescope;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Telescope with `TELESCOPE_RECORD_ALL=true` writes a row per request, per query and
 * per model event, so on a deployment it is a table that only grows. 48 hours is long
 * enough to walk back through yesterday's test run and short enough that the panel
 * stays fast on shared hosting.
 *
 * The command is registered only when Telescope is actually loaded — `AppServiceProvider`
 * skips the provider when the package is absent or disabled, and scheduling a command
 * that does not exist fails the whole `schedule:run`.
 */
if (class_exists(Telescope::class) && config('telescope.enabled') === true) {
    Schedule::command('telescope:prune --hours=48')->daily();
}
