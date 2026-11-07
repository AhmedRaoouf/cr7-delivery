<?php

namespace App\Actions\Admin;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessTime;
use App\Support\PlaceAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §7 — POST /admin/settlements/generate {party_type, party_id, period_start, period_end}.
 *
 * doc 62 §4.4 — a place's statement, for the money that actually moves through the platform.
 *
 * The old formula was `gross − commission − refunds − Σ total of cash orders`. The last
 * term was the **customer's** total — delivery and service fee included — so every cash
 * order pushed the place a delivery fee further into debt to the platform, and a place
 * that only ever took cash ended every period owing money for food it had sold. Under
 * D-103 the captain pays the place its own figure at the counter, so what the platform
 * owes a place is its food money on the orders the customer paid **online**, and nothing
 * on cash orders:
 *
 *     net = Σ restaurant_amount (online) − refunds attributable
 *     cash_collected := Σ restaurant_amount (cash)    «دفعه الكباتن عند الاستلام»
 *
 * An order is claimed by the statement that includes it (`orders.restaurant_settlement_id`)
 * and can be on one statement only — two overlapping periods used to pay the same order
 * twice.
 *
 * doc 103 — a place pays a commission on its food, and on a cash order it takes the
 * whole food from the captain («يتم دفع قيمة الطلب المدفوع نقدًا بالكامل للتاجر»). Every
 * order's line is `Order::placeBalance()` — what the place earns on it less what it
 * already took at the counter — and the statement is their sum:
 *
 *     net = Σ (subtotal − commission − place discount) − Σ paid at pickup − refunds
 *
 * (`PlaceAccount`, shared with the finance list and the place's reports; a refund is
 * charged only up to what the place earned on that order)
 *
 * Positive, the platform pays the place; negative, the place pays the platform
 * («يدفع الطرف المدين مديونيته للطرف الدائن»). Days are Cairo days.
 *
 * A captain is no longer settled here. A period statement summed `driver_earning` and
 * ignored the cash the captain was carrying, which is the one number that matters; a
 * captain is settled from his live ledger (`POST /admin/drivers/{driver}/settle`).
 */
class GenerateSettlementAction
{
    /**
     * @param  User|null  $admin  null when the 14-day cycle writes the statement itself
     *                            (`cr7:settlement-cycle`).
     */
    public function execute(?User $admin, string $partyType, int $partyId, string $periodStart, string $periodEnd): Settlement
    {
        if ($partyType === 'driver') {
            throw new ApiException(
                'driver_settles_from_ledger',
                'تسوية الكابتن تتم من شاشة حسابه: ما حصّله نقدًا وما يستحقه، ثم «تمت التسوية»',
                422,
            );
        }

        return DB::transaction(function () use ($admin, $partyType, $partyId, $periodStart, $periodEnd) {
            $orders = self::unsettled($partyId, $periodStart, $periodEnd)->lockForUpdate()->get();

            if ($orders->isEmpty()) {
                throw new ApiException('nothing_to_settle', 'لا توجد طلبات مسلّمة غير مسوّاة لهذا المكان في هذه الفترة', 422);
            }

            $account = PlaceAccount::of($orders);
            $refunds = PlaceAccount::refunds($orders->reject(fn (Order $order) => $order->isDirect()));
            $isCash = fn (Order $order) => $order->payment_method?->value === 'cash';
            $netAmount = $account['balance'];

            $settlement = Settlement::create([
                'party_type' => $partyType,
                'party_id' => $partyId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'gross_sales' => $account['food_total'],
                // doc 103 — each order's refund charged only up to what the place earned on it.
                'refunds_total' => $account['refunds_total'],
                'commission_total' => $account['commission_total'],
                'delivery_fees_total' => (float) $orders->sum('delivery_fee_share'),
                // D-70 — the platform's 15 % of those fees, summed from the per-order
                // snapshots rather than re-derived, so a rate change after the fact cannot
                // move a period that has already been settled.
                'platform_delivery_margin_total' => (float) $orders->sum('platform_delivery_margin'),
                // D-63 — reported, never paid out.
                'platform_fee_total' => (float) $orders->sum('platform_fee_share'),
                // «دفعه الكباتن عند الاستلام» — the column keeps its name; its meaning is
                // what the place already received at the counter.
                'cash_collected' => $account['paid_at_pickup_total'],
                'net_amount' => $netAmount,
                'breakdown' => [
                    'orders_count' => $account['orders_count'],
                    'online_orders_count' => $account['online_orders_count'],
                    'online_food_total' => $account['online_food_total'],
                    // What the platform owes the place on its online orders (the old key).
                    'online_total' => $account['online_owed_to_place'],
                    'online_commission_total' => $account['online_commission_total'],
                    'cash_orders_count' => $account['cash_orders_count'],
                    'cash_food_total' => $account['cash_food_total'],
                    'paid_at_pickup_total' => $account['paid_at_pickup_total'],
                    'cash_commission_total' => $account['cash_commission_total'],
                    // What the place owes the platform on its cash orders.
                    'cash_owed_by_place' => $account['cash_owed_by_place'],
                    'place_discount_total' => $account['place_discount_total'],
                    'direct_orders_count' => $account['direct_orders_count'],
                    // Who pays whom, so no screen has to read a sign.
                    'direction' => $account['direction'],
                    'automatic' => $admin === null,
                ],
                'status' => 'draft',
                'created_by' => $admin?->id,
            ]);

            foreach ($orders as $order) {
                $type = match (true) {
                    $order->isDirect() => 'direct',
                    $isCash($order) => 'cash_paid_at_pickup',
                    default => 'online',
                };

                $settlement->items()->create([
                    'order_id' => $order->id,
                    'type' => $type,
                    'gross' => $order->subtotal,
                    'commission' => $order->commission_amount,
                    'paid_at_pickup' => $type === 'cash_paid_at_pickup' ? PlaceAccount::paidAtPickup($order) : 0,
                    'place_discount' => $order->place_discount,
                    'delivery_fee' => $order->delivery_fee_share,
                    'platform_delivery_margin' => $order->platform_delivery_margin,
                    'platform_fee' => $order->platform_fee_share,
                    'driver_earning' => $order->driver_earning,
                    // This order's line: positive, the platform owes the place; negative,
                    // the place owes the platform. A direct order moves no food money.
                    'net' => $type === 'direct' ? 0 : round($order->placeBalance() - ($refunds[$order->id] ?? 0.0), 2),
                ]);
            }

            Order::query()->whereIn('id', $orders->pluck('id'))->update(['restaurant_settlement_id' => $settlement->id]);

            if ($admin !== null) {
                AuditLogger::record($admin, 'settlements.generate', $settlement, ['party_type' => $partyType, 'party_id' => $partyId]);
            }

            return $settlement->load('items');
        });
    }

    /**
     * A place's delivered orders no statement has claimed, delivered on the Cairo days
     * `$periodStart`…`$periodEnd` inclusive.
     *
     * @return Builder<Order>
     */
    public static function unsettled(int $restaurantId, string $periodStart, string $periodEnd): Builder
    {
        return Order::query()
            ->where('status', 'delivered')
            ->where('restaurant_id', $restaurantId)
            ->whereNull('restaurant_settlement_id')
            ->whereBetween('delivered_at', [
                BusinessTime::fromInput(Carbon::parse($periodStart)->startOfDay()),
                BusinessTime::fromInput(Carbon::parse($periodEnd)->startOfDay(), true),
            ]);
    }

    /** `platform_pays_place` · `place_pays_platform` · `settled` (nothing moves). */
    public static function direction(float $net): string
    {
        return PlaceAccount::direction($net);
    }
}
