<?php

namespace App\Console\Commands;

use App\Actions\Restaurant\MarkOrderReadyAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Models\Order;
use App\Models\Setting;
use App\Support\DispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The clock this application never had.
 *
 * Four deadlines are written into the database on every order and, until this
 * command existed, not one of them was ever read back:
 *
 * | Column | Set by | Was supposed to | Actually did |
 * |---|---|---|---|
 * | `orders.prep_due_at` | `StartPreparingAction` | move `preparing → ready` (doc 04 §10.4) | nothing |
 * | `order_driver_attempts.expires_at` | `DispatchService` | end a round after 30 s (doc 05 §3) | nothing |
 * | `orders.created_at` + `restaurant.accept_timeout_minutes` | seed | move `pending → restaurant_timeout` | nothing |
 * | — | — | retry an order nobody took (doc 04 E-71) | nothing |
 *
 * `DEPLOY.md` §9 states outright that «ordering works with no cron and no queue
 * worker at all», having been corrected once to say so. That is true of the happy
 * path and false of every path where a human does not press the button: a kitchen
 * that forgets, a captain whose phone is in a pocket, a restaurant that never
 * answers. All three simply stopped, and the order stopped with them.
 *
 * **One process, no queue.** There is no worker on this host and no `app/Jobs`, so
 * this is a plain command driven by the `* * * * *` crontab entry §9 already asks
 * for. Cron's floor is one minute and `dispatch.offer_ttl_seconds` is thirty, so a
 * single invocation loops for most of a minute at `--sleep` resolution rather than
 * leaving a 30-second offer to be noticed 60 seconds late.
 *
 * Every sweep is wrapped: one poisoned order must not stop the other three sweeps
 * or the orders behind it in the same one.
 */
class TickCommand extends Command
{
    protected $signature = 'cr7:tick
        {--loop=3 : passes per invocation — cron fires once a minute, offers expire in 30 s}
        {--sleep=18 : seconds between passes}
        {--once : a single pass, for tests and for running it by hand}';

    protected $description = 'Expire prep timers, advance dispatch rounds, time out unanswered orders, retry stranded ones';

    /** A second cron firing while the first still runs would double-offer every order. */
    private const LOCK_KEY = 'cr7:tick';

    private const LOCK_SECONDS = 110;

    /**
     * An order left `pending` for days is not a customer waiting for an answer — it is
     * abandoned data. Timing those out would notify people about orders they forgot
     * existed, so the sweep only speaks for orders young enough to still be real.
     */
    private const TIMEOUT_MAX_AGE_HOURS = 24;

    public function handle(DispatchService $dispatch, MarkOrderReadyAction $markReady): int
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->comment('another tick is still running — skipping');

            return self::SUCCESS;
        }

        try {
            $passes = $this->option('once') ? 1 : max(1, (int) $this->option('loop'));
            $sleep = max(1, (int) $this->option('sleep'));

            for ($pass = 1; $pass <= $passes; $pass++) {
                $this->pass($dispatch, $markReady);

                if ($pass < $passes) {
                    sleep($sleep);
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function pass(DispatchService $dispatch, MarkOrderReadyAction $markReady): void
    {
        $this->sweep('prep timers', fn () => $this->expirePrepTimers($markReady));
        $this->sweep('dispatch rounds', fn () => $this->advanceRounds($dispatch));
        $this->sweep('accept windows', fn () => $this->expireAcceptWindows());
        $this->sweep('stranded orders', fn () => $this->retryStranded($dispatch));
    }

    /** @param callable():int $work */
    private function sweep(string $name, callable $work): void
    {
        try {
            $touched = $work();

            if ($touched > 0) {
                $this->line(sprintf('%-16s %d', $name, $touched));
            }
        } catch (Throwable $e) {
            // One bad order must not cost the other three sweeps their pass.
            Log::error('cr7:tick sweep failed', ['sweep' => $name, 'error' => $e->getMessage()]);
            $this->error($name.': '.$e->getMessage());
        }
    }

    /**
     * doc 04 §10.4 — the countdown owns `preparing → ready`, not the kitchen.
     *
     * The restaurant committed to the number at accept and may extend it once. What it
     * may not do is let the order sit silently, which is exactly what happened for as
     * long as nothing read `prep_due_at`.
     */
    private function expirePrepTimers(MarkOrderReadyAction $markReady): int
    {
        $orders = Order::query()
            ->where('status', OrderStatus::Preparing)
            ->whereNotNull('prep_due_at')
            ->where('prep_due_at', '<=', now())
            ->with('restaurant.owner')
            ->limit(50)
            ->get();

        foreach ($orders as $order) {
            $actor = $order->restaurant?->owner;

            if ($actor === null) {
                continue;
            }

            // `source: 'timer'` is what writes `actor_type = system` on the history row
            // and what `OrderNotifications::ready()` reads to tell the restaurant its
            // timer, not its hand, moved the order.
            $markReady->execute($actor, $order, 'timer');
        }

        return $orders->count();
    }

    /** doc 05 §3 — a round ends when its last offer is answered or lapses. */
    private function advanceRounds(DispatchService $dispatch): int
    {
        $orders = Order::query()
            ->where('status', OrderStatus::SearchingDriver)
            ->whereHas('driverAttempts', fn ($q) => $q
                ->where('result', 'sent')
                ->where('expires_at', '<=', now()))
            ->limit(50)
            ->get();

        foreach ($orders as $order) {
            $dispatch->advanceIfRoundExhausted($order);
        }

        return $orders->count();
    }

    /**
     * doc 04 — `pending → restaurant_timeout` after `restaurant.accept_timeout_minutes`.
     *
     * The status has existed in the enum, the board («طلبات جديدة» holds both) and the
     * refund policy since day one. Nothing has ever written it, so a restaurant that
     * ignored an order left the customer on «بانتظار موافقة المطعم» indefinitely with
     * no refund path and no notification.
     *
     * A direct order is exempt: the restaurant wrote it, so there is nobody to answer.
     */
    private function expireAcceptWindows(): int
    {
        $minutes = (int) (Setting::query()->where('key', 'restaurant.accept_timeout_minutes')->value('value') ?? 5);

        $orders = Order::query()
            ->where('status', OrderStatus::Pending)
            ->where('origin', '!=', 'restaurant_direct')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->where('created_at', '>=', now()->subHours(self::TIMEOUT_MAX_AGE_HOURS))
            ->limit(50)
            ->get();

        foreach ($orders as $order) {
            $order->update(['status' => OrderStatus::RestaurantTimeout]);

            $order->statusHistory()->create([
                'from_status' => OrderStatus::Pending->value,
                'to_status' => OrderStatus::RestaurantTimeout->value,
                'actor_type' => OrderStatusActorType::System,
                'actor_id' => null,
            ]);
        }

        return $orders->count();
    }

    /**
     * doc 04 E-71 — `waiting_for_driver` is «nobody took it», not «nobody ever will».
     *
     * Before this, that status was terminal in practice: the rounds were spent inside a
     * single request and no code path ever looked at the order again. An operator's only
     * move was «إعادة توزيع الطلب» by hand, one order at a time, which is what the
     * seventeen stranded orders on the deployment were waiting for.
     *
     * The retry starts at round 1 — a fresh circle, not a wider one — and forgives a
     * captain who let an offer lapse while excluding one who declined it.
     */
    private function retryStranded(DispatchService $dispatch): int
    {
        $seconds = (int) (Setting::query()->where('key', 'dispatch.retry_interval_seconds')->value('value') ?? 90);

        $orders = Order::query()
            ->where('status', OrderStatus::WaitingForDriver)
            ->where('updated_at', '<=', now()->subSeconds($seconds))
            // Same reasoning as the accept window: an order stranded since last week is
            // an operator's problem, not a dispatcher's.
            ->where('created_at', '>=', now()->subHours(self::TIMEOUT_MAX_AGE_HOURS))
            ->with('restaurant')
            ->limit(25)
            ->get();

        foreach ($orders as $order) {
            $dispatch->dispatchRound($order, 1, retry: true);
        }

        return $orders->count();
    }
}
