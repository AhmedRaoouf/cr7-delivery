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
 *
 * 🔴 **The argument is not optional, and leaving it off stopped the order machine
 * for a week.** `withoutOverlapping()` defaults to **‪1440‬ minutes** — a full day.
 * It is a *stale-lock expiry*, not a run length: the lock is released in a
 * `then()` callback, so a pass that is killed rather than finished — a deploy, a
 * dropped SSH session, an OOM — never releases it, and every invocation for the
 * next twenty-four hours is silently filtered out.
 *
 * There is no error when that happens. `schedule:run` prints «No scheduled
 * commands are ready to run», `schedule:list` still shows the entry as due, the
 * crontab is still firing, and `storage/logs/cron.log` stops growing. On
 * 2026-09-12 the lock was found held until ‪13:09‬ that day, acquired ‪24‬ hours
 * earlier; with it held, the prep timer, the ‪120‬-second rebroadcast, the
 * unclaimed-order escalation, the accept-window expiry and scheduled-order
 * release were all dead, and the diagnosis had been «the hPanel cron entry is
 * missing» — it was there the whole time.
 *
 * ‪2‬ minutes is the right number because it is **just above the braces**:
 * `TickCommand::LOCK_SECONDS` is ‪110‬, and that lock is a real `Cache::lock`
 * with a TTL, so it heals itself on the next minute regardless. The belt must
 * not outlast it by more than a tick, or the belt becomes the failure mode.
 */
Schedule::command('cr7:tick')->everyMinute()->withoutOverlapping(2);

/*
 * D-120 — the session tables. Both are cleanup: nothing they delete can authenticate, so
 * a pass that never runs costs disk, not correctness.
 *
 * `sanctum:prune-expired` deletes access tokens a day past their `expires_at` — an hour-
 * long token leaves a row behind every hour a session is used. `cr7:prune-sessions` deletes
 * refresh tokens dead for a week and legacy tokens a week past their idle window, and
 * scrubs the successor pairs held for the ‪120‬-second grace window. That last part is why
 * it runs hourly rather than daily: a scrubbed pair is a live session no longer sitting
 * in the table in a form that can be read back.
 *
 * Both carry an explicit `withoutOverlapping` expiry — see the paragraph above `cr7:tick`
 * for what the default of ‪1440‬ minutes did. Each pass is a handful of indexed deletes;
 * the numbers only have to outlast one.
 */
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping(30);
Schedule::command('cr7:prune-sessions')->hourly()->withoutOverlapping(30);

/*
 * Batch 68 — the SMS ledger, dead verification codes and long-ended number blocks. Every
 * cap counts at most ‪24‬ hours back, so a pass that never runs costs disk, not
 * correctness. An explicit `withoutOverlapping` expiry, for the reason written above
 * `cr7:tick`.
 */
Schedule::command('cr7:prune-otp')->daily()->withoutOverlapping(30);
