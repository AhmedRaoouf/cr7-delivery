<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * 2026-11-06 — `dispatch.strategy = auto_offer`.
 *
 * Owners, after a day of D-171's direct assignment: «ميروحش على طول للدليفري ويقبل، إنما
 * يروح له الشاشة زي ما كنا عاملين وهو يقدر يختار يقبل أو يرفض» + «مش لازم يبقى جاهز …
 * تلقائيًا يروح للكباتن القريبين الفاضيين الـ online».
 *
 * So the **who** and the **when** are AutoAssign's — connected, a recent fix, through the
 * trip gates, nearest to the place first, at `prep_due_at − lead` (at once with less left)
 * — and the **what** is the old offer: an `order_driver_attempts` row per captain and the
 * `offer.new` ring, answered on the offer screen every app version already has. The order
 * stays `preparing` on the place's board; a captain's «قبول» makes it `driver_assigned`
 * with `ready_at = null`, exactly the state auto-assign left it in.
 *
 * A captain who refuses is not asked again while it cooks. When the food is ready and
 * nobody has taken it, ordinary dispatch takes over (`MarkOrderReadyAction` →
 * `DispatchService::dispatchRound`), every open offer still standing, and the two-minute
 * rebroadcast asks everyone again.
 */
final class EarlyOffer
{
    public static function enabled(): bool
    {
        return AutoAssign::offers();
    }

    /** @return int how many captains were rung for this order */
    public function run(Order $order): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $order = $order->fresh() ?? $order;

        if ($order->driver_id !== null || $order->status !== OrderStatus::Preparing || ! AutoAssign::isDue($order)) {
            return 0;
        }

        // D-115 — a sibling of a basket somebody carries is theirs; the basket rule hands it
        // over at `ready`.
        if (DriverTrip::holderFor($order) !== null) {
            return 0;
        }

        $candidates = app(AutoAssign::class)->candidates($order, connectedOnly: false);

        if ($candidates->isEmpty()) {
            if ($order->dispatch_started_at === null) {
                $order->update(['dispatch_started_at' => now()]);
            }

            return 0;
        }

        $holding = $order->driverAttempts()->where('result', 'sent')->pluck('driver_id')
            ->map(fn ($id) => (int) $id)->all();

        // Every rebroadcast interval the captains already holding it are rung again, as
        // the broadcast does after `ready`; in between only newcomers hear.
        $interval = max(30, SettingsCatalog::int('dispatch.rebroadcast_interval_seconds'));
        $ringAgain = $order->dispatch_last_offer_at === null
            || $order->dispatch_last_offer_at->lte(now()->subSeconds($interval));

        $round = max(1, (int) $order->dispatch_round);
        $dispatch = app(DispatchService::class);
        $rung = 0;

        foreach ($candidates as $candidate) {
            $id = (int) $candidate['driver']->id;
            $new = ! in_array($id, $holding, true);

            if (! $new && ! $ringAgain) {
                continue;
            }

            if ($new) {
                $order->driverAttempts()->create([
                    'driver_id' => $id,
                    'round' => $round,
                    'distance_km' => round((float) $candidate['distance_km'], 3),
                    'result' => 'sent',
                    'sent_at' => now(),
                    'expires_at' => null,
                ]);
            }

            $dispatch->wake($order, $candidate, $round);
            $rung++;
        }

        if ($rung > 0) {
            $order->update([
                'dispatch_round' => $round,
                'dispatch_started_at' => $order->dispatch_started_at ?? now(),
                'dispatch_last_offer_at' => now(),
            ]);
        }

        return $rung;
    }

    /**
     * Every cooking order without a captain that is due — `cr7:tick` and a captain coming
     * free run it. Idempotent: a captain already holding the offer gets no second row.
     *
     * @return int how many orders rang somebody
     */
    public function sweep(int $limit = 25): int
    {
        if (! self::enabled()) {
            return 0;
        }

        $orders = Order::query()
            ->whereNull('driver_id')
            ->where('status', OrderStatus::Preparing->value)
            ->where('created_at', '>=', now()->subHours(24))
            ->where(fn ($d) => $d
                ->whereNull('prep_due_at')
                ->orWhere('prep_due_at', '<=', now()->addMinutes(AutoAssign::leadMinutes())))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $offered = 0;

        foreach ($orders as $order) {
            try {
                if ($this->run($order) > 0) {
                    $offered++;
                }
            } catch (\Throwable $e) {
                Log::warning('early offer failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return $offered;
    }
}
