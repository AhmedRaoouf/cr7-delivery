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

/*
 * The order machine's clock. Four deadlines are written on every order —
 * `prep_due_at`, the offer TTL, the restaurant's accept window and the retry
 * interval for a stranded order — and before this line none of them was read.
 *
 * `DEPLOY.md` §9 asks for `* * * * * php artisan schedule:run`; that entry now
 * does more than prune Telescope. One minute is the crontab's floor and an offer
 * lives thirty seconds, so the command loops inside its own minute rather than
 * being scheduled more often than cron can fire.
 *
 * `withoutOverlapping` is belt to the command's own cache lock's braces: a pass
 * that runs long must never have a second one dispatching the same order.
 */
Schedule::command('cr7:tick')->everyMinute()->withoutOverlapping();
