<?php

namespace App\Console\Commands;

use App\Actions\Customer\ReleaseScheduledOrdersAction;
use App\Actions\Restaurant\MarkOrderReadyAction;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Enums\UserType;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdminScope;
use App\Support\DispatchService;
use App\Support\ErrandDispatcher;
use App\Support\Notifications\NotificationDispatcher;
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

    public function handle(
        DispatchService $dispatch,
        MarkOrderReadyAction $markReady,
        ErrandDispatcher $errands,
        ReleaseScheduledOrdersAction $scheduled,
    ): int {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->comment('another tick is still running — skipping');

            return self::SUCCESS;
        }

        try {
            $passes = $this->option('once') ? 1 : max(1, (int) $this->option('loop'));
            $sleep = max(1, (int) $this->option('sleep'));

            for ($pass = 1; $pass <= $passes; $pass++) {
                $this->pass($dispatch, $markReady, $errands, $scheduled);

                if ($pass < $passes) {
                    sleep($sleep);
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function pass(
        DispatchService $dispatch,
        MarkOrderReadyAction $markReady,
        ErrandDispatcher $errands,
        ReleaseScheduledOrdersAction $scheduled,
    ): void {
        $this->sweep('prep timers', fn () => $this->expirePrepTimers($markReady));
        $this->sweep('dispatch rounds', fn () => $this->advanceRounds($dispatch));
        $this->sweep('accept windows', fn () => $this->expireAcceptWindows());
        // 2026-09-08 — «لو ما حدش قبل في أول دقيقة ابعت لناس تانية… وهكذا». Offers no
        // longer expire, so widening the search is on a clock of its own rather than
        // riding on the back of a dead round.
        $this->sweep('widen search', fn () => $this->widenSearches($dispatch));
        $this->sweep('unclaimed orders', fn () => $this->escalateUnclaimed());
        // doc 40 §3 — an errand nobody took. Notifies once and leaves it open: the
        // operator assigning it by hand is the fallback, not the only path.
        $this->sweep('stranded errands', fn () => $errands->escalateStranded());
        // The errand half of the same ruling: a captain who came online after the
        // request was published had never been told it existed.
        $this->sweep('republish errands', fn () => $errands->republishOpen());
        // «الساعة ستة اللي هو عاملها تيجي فيقوم رايح يبعت». The minute a scheduled
        // basket becomes a real order — through the same `CreateOrderAction` a
        // customer's own checkout uses, so there is no second path to keep in step.
        $this->sweep('scheduled orders', function () use ($scheduled) {
            $result = $scheduled->releaseDue();

            return $result['released'] + $result['failed'];
        });
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
                // Legacy rows only. Since 2026-09-08 an offer has no deadline, and
                // the widening sweep below is what keeps such an order moving.
                ->whereNotNull('expires_at')
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
     * Every minute, ask somebody new.
     *
     * The old ladder needed an offer to die before the next rung could be climbed, and
     * offers no longer die. So the order carries the clock instead: `dispatch_last_offer_at`
     * is when the last batch went out, and an order still looking for a captain a minute
     * later gets another batch — the captains already holding an offer are skipped, not
     * re-rung, and their offers stay live.
     *
     * `waiting_for_driver` is included on purpose. That status now means «nobody new was
     * found on the last pass», which is a sentence about a moment, not about the order.
     */
    private function widenSearches(DispatchService $dispatch): int
    {
        $seconds = self::seconds('dispatch.rebroadcast_interval_seconds', 120);

        $orders = Order::query()
            ->whereIn('status', [OrderStatus::SearchingDriver, OrderStatus::WaitingForDriver])
            ->whereNull('driver_id')
            ->where(fn ($q) => $q
                ->whereNull('dispatch_last_offer_at')
                ->orWhere('dispatch_last_offer_at', '<=', now()->subSeconds($seconds)))
            ->where('created_at', '>=', now()->subHours(self::TIMEOUT_MAX_AGE_HOURS))
            ->with('restaurant')
            ->limit(25)
            ->get();

        $rung = 0;

        foreach ($orders as $order) {
            // 2026-09-11: the whole shift, every two minutes, not only whoever is new
            // since the last pass. See `DispatchService::rebroadcast()`.
            if ($dispatch->rebroadcast($order) > 0) {
                $rung++;
            }
        }

        return $rung;
    }

    /**
     * Tell the operators once, and keep the order searching.
     *
     * With no TTL an order can no longer «run out of rounds», so the alert dock would
     * never have heard about one nobody wanted. `dispatch_admin_notified_at` is the same
     * once-only stamp `errand_requests.admin_notified_at` is, for the same reason: a
     * sweep that runs every minute must not send a push every minute.
     */
    private function escalateUnclaimed(): int
    {
        $seconds = self::seconds('dispatch.escalate_after_seconds', 120);

        $orders = Order::query()
            ->whereIn('status', [OrderStatus::Ready, OrderStatus::SearchingDriver, OrderStatus::WaitingForDriver])
            ->whereNull('driver_id')
            ->whereNull('dispatch_admin_notified_at')
            ->whereNotNull('dispatch_started_at')
            ->where('dispatch_started_at', '<=', now()->subSeconds($seconds))
            ->where('created_at', '>=', now()->subHours(self::TIMEOUT_MAX_AGE_HOURS))
            ->with(['restaurant.owner'])
            ->limit(25)
            ->get();

        if ($orders->isEmpty()) {
            return 0;
        }

        $admins = User::query()->where('type', UserType::Admin)->where('is_active', true)->get();
        $notifications = app(NotificationDispatcher::class);
        $minutes = (int) ceil($seconds / 60);
        $supportPhone = (string) (Setting::query()->where('key', 'support.phone')->value('value') ?? '');

        foreach ($orders as $order) {
            /*
             * Only the operators who could act on it. D-51: a Gamasa sub-admin is not
             * told that a New Mansoura order is stuck — and if they tapped the push they
             * would get the 404 that same decision guarantees.
             */
            foreach ($admins as $admin) {
                if (! AdminScope::coversOrder($admin, $order)) {
                    continue;
                }

                $notifications->send($admin, 'order.unclaimed_admin', [
                    'order_number' => $order->order_number,
                    'restaurant' => $order->restaurant?->name_ar,
                    'minutes' => $minutes,
                ], deepLink: '/orders/'.$order->id, orderId: $order->id, data: ['order_id' => (string) $order->id]);
            }

            /*
             * And the kitchen, which is standing over food nobody has come for.
             *
             * Operator, 2026-09-11: «يروح اشعار للادمن وابعت اشعار للمطعم تواصل مع الدعم».
             * It used to be told the moment the dispatcher ran out of *new* captains to
             * ask, which could be the same second the food was marked ready.
             */
            if ($order->restaurant?->owner !== null) {
                $notifications->send($order->restaurant->owner, 'order.no_driver_restaurant', [
                    'order_number' => $order->order_number,
                    'minutes' => $minutes,
                    'support_phone' => $supportPhone,
                ], deepLink: '/orders/'.$order->id, orderId: $order->id, data: ['order_id' => (string) $order->id]);
            }

            // Stamped whether or not anyone was there to tell — otherwise a deployment
            // with no active operators rescans the same rows forever. Cleared again when
            // a captain takes the order, so a second stranding is a second alert.
            $order->forceFill(['dispatch_admin_notified_at' => now()])->save();
        }

        return $orders->count();
    }

    private static function seconds(string $key, int $fallback): int
    {
        $value = Setting::query()->where('key', $key)->value('value');

        return is_numeric($value) ? max(15, (int) $value) : $fallback;
    }

    /*
     * `retryStranded()` is **gone** (2026-09-11).
     *
     * It read `updated_at ≤ now − 90 s`, and the widening sweep above bumps `updated_at`
     * every pass — so it had not fired since 2026-09-08 and nobody noticed, which is the
     * clearest argument there is for one clock instead of two. `rebroadcast()` covers
     * both `searching_driver` and `waiting_for_driver` every two minutes.
     */
}
