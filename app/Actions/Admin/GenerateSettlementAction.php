<?php

namespace App\Actions\Admin;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Settlement;
use App\Models\User;
use App\Support\AuditLogger;
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
 * A captain is no longer settled here. A period statement summed `driver_earning` and
 * ignored the cash the captain was carrying, which is the one number that matters; a
 * captain is settled from his live ledger (`POST /admin/drivers/{driver}/settle`).
 */
class GenerateSettlementAction
{
    public function execute(User $admin, string $partyType, int $partyId, string $periodStart, string $periodEnd): Settlement
    {
        if ($partyType === 'driver') {
            throw new ApiException(
                'driver_settles_from_ledger',
                'تسوية الكابتن تتم من شاشة حسابه: ما حصّله نقدًا وما يستحقه، ثم «تمت التسوية»',
                422,
            );
        }

        return DB::transaction(function () use ($admin, $partyType, $partyId, $periodStart, $periodEnd) {
            $orders = Order::query()
                ->where('status', 'delivered')
                ->where('restaurant_id', $partyId)
                ->whereNull('restaurant_settlement_id')
                ->whereBetween('delivered_at', [Carbon::parse($periodStart)->startOfDay(), Carbon::parse($periodEnd)->endOfDay()])
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                throw new ApiException('nothing_to_settle', 'لا توجد طلبات مسلّمة غير مسوّاة لهذا المكان في هذه الفترة', 422);
            }

            $isCash = fn (Order $order) => $order->payment_method?->value === 'cash';
            $marketplace = $orders->reject(fn (Order $order) => $order->isDirect());
            $online = $marketplace->reject($isCash);
            $cash = $marketplace->filter($isCash);

            $grossSales = (float) $orders->sum('subtotal');
            $commissionTotal = (float) $orders->sum('commission_amount');
            $deliveryFeesTotal = (float) $orders->sum('delivery_fee_share');
            // D-70 — the platform's 15 % of those fees, summed from the per-order
            // snapshots rather than re-derived, so a rate change after the fact cannot
            // move a period that has already been settled.
            $platformDeliveryMarginTotal = (float) $orders->sum('platform_delivery_margin');
            // D-63 — reported, never paid out.
            $platformFeeTotal = (float) $orders->sum('platform_fee_share');
            $refundsTotal = (float) Refund::query()->whereIn('order_id', $orders->pluck('id'))->where('status', 'processed')->sum('amount');
            $paidAtPickup = round((float) $cash->sum(fn (Order $order) => $order->restaurant_amount), 2);

            $netAmount = round(
                $grossSales - $commissionTotal - $refundsTotal - $paidAtPickup,
                2,
            );

            $settlement = Settlement::create([
                'party_type' => $partyType,
                'party_id' => $partyId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'gross_sales' => $grossSales,
                'refunds_total' => $refundsTotal,
                'commission_total' => $commissionTotal,
                'delivery_fees_total' => $deliveryFeesTotal,
                'platform_delivery_margin_total' => $platformDeliveryMarginTotal,
                'platform_fee_total' => $platformFeeTotal,
                // «دفعه الكباتن عند الاستلام» — the column keeps its name; its meaning is
                // now what the place already received at the counter.
                'cash_collected' => $paidAtPickup,
                'net_amount' => $netAmount,
                'breakdown' => [
                    'online_orders_count' => $online->count(),
                    'online_total' => round((float) $online->sum(fn (Order $order) => $order->restaurant_amount), 2),
                    'cash_orders_count' => $cash->count(),
                    'paid_at_pickup_total' => $paidAtPickup,
                    'direct_orders_count' => $orders->filter(fn (Order $order) => $order->isDirect())->count(),
                ],
                'status' => 'draft',
                'created_by' => $admin->id,
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
                    'delivery_fee' => $order->delivery_fee_share,
                    'platform_delivery_margin' => $order->platform_delivery_margin,
                    'platform_fee' => $order->platform_fee_share,
                    'driver_earning' => $order->driver_earning,
                    // What this order adds to what the platform owes the place.
                    'net' => $type === 'online' ? $order->restaurant_amount : 0,
                ]);
            }

            Order::query()->whereIn('id', $orders->pluck('id'))->update(['restaurant_settlement_id' => $settlement->id]);

            AuditLogger::record($admin, 'settlements.generate', $settlement, ['party_type' => $partyType, 'party_id' => $partyId]);

            return $settlement->load('items');
        });
    }
}
