<?php

namespace Tests\Feature;

use App\Console\Commands\TickCommand;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * 🔴 The order machine was dead for a week, and the cause was a default argument.
 *
 * `routes/console.php` had `->withoutOverlapping()` with no argument.
 * `Illuminate\Console\Scheduling\Event::$expiresAt` defaults to **‪1440‬** — a
 * full day — and it is a *stale-lock expiry*, not a run length. The lock is
 * released from a `then()` callback, so a pass that is **killed** rather than
 * finished never releases it: a deploy, a dropped SSH session, an OOM kill. For
 * the next twenty-four hours every invocation is filtered out.
 *
 * Nothing anywhere says so. `schedule:list` still shows the entry as due, the
 * crontab still fires every minute, and `schedule:run` prints the cheerful «No
 * scheduled commands are ready to run» — the message for an empty schedule, not
 * for a blocked one. The only visible symptom was `storage/logs/cron.log`
 * sitting at ‪0‬ bytes, and that was read for a week as «the hPanel cron entry is
 * missing». It was there the whole time.
 *
 * With the lock held, `prep_due_at`, the offer TTL, the ‪120‬-second rebroadcast,
 * the restaurant accept window, the unclaimed-order escalation and
 * scheduled-order release are **all** dead, and no customer or captain is told
 * anything.
 */
class ScheduleMutexTest extends TestCase
{
    private function tick(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, 'cr7:tick'));

        $this->assertNotNull($event, 'cr7:tick must be on the schedule at all — it is the order machine\'s clock');

        return $event;
    }

    public function test_the_clock_runs_every_minute(): void
    {
        $this->assertSame('* * * * *', $this->tick()->expression);
    }

    public function test_a_killed_pass_cannot_block_the_clock_for_a_day(): void
    {
        $expiry = $this->tick()->expiresAt;

        // The number that was there. Asserting on it by name rather than by
        // value, because «‪1440‬» reads like a deliberate choice and was not one.
        $this->assertNotSame(
            1440,
            $expiry,
            'withoutOverlapping() was called with no argument — one killed pass stops dispatch for 24 hours',
        );

        $this->assertLessThanOrEqual(5, $expiry, 'a stale lock must heal within a few ticks, not within a day');
    }

    public function test_the_schedule_lock_outlasts_the_command_lock_by_about_a_tick(): void
    {
        // `withoutOverlapping` is the belt; `TickCommand`'s own `Cache::lock` is
        // the braces, and unlike the belt it is a real TTL that heals itself. The
        // belt has to cover a pass that is still running — so it must be longer —
        // without becoming the thing that outlives a crash, so barely longer.
        $braces = (new ReflectionClassConstant(TickCommand::class, 'LOCK_SECONDS'))->getValue();
        $belt = $this->tick()->expiresAt * 60;

        $this->assertGreaterThan($braces, $belt, 'the schedule lock must cover a full-length pass');
        $this->assertLessThan($braces * 3, $belt, 'and must not outlive it by much — that is how the week was lost');
    }
}
