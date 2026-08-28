<?php

namespace App\Actions\Admin;

use App\Models\Order;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** doc 08 §7 — POST /admin/settlements/generate {party_type, party_id, period_start, period_end}. */
class GenerateSettlementAction
{
    public function execute(User $admin, string $partyType, int $partyId, string $periodStart, string $periodEnd): Settlement
    {
        return DB::transaction(function () use ($admin, $partyType, $partyId, $periodStart, $periodEnd) {
            $orders = Order::query()
                ->where('status', 'delivered')
                ->whereBetween('delivered_at', [Carbon::parse($periodStart)->startOfDay(), Carbon::parse($periodEnd)->endOfDay()])
                ->when($partyType === 'restaurant', fn ($q) => $q->where('restaurant_id', $partyId))
                ->when($partyType === 'driver', fn ($q) => $q->where('driver_id', $partyId))
                ->get();

            $grossSales = (float) $orders->sum('subtotal');
            $commissionTotal = (float) $orders->sum('commission_amount');
            $deliveryFeesTotal = (float) $orders->sum('delivery_fee_share');
            // D-63 — reported, never paid out. It stays outside `net_amount` for both
            // party types: neither the restaurant nor the captain earned it.
            $platformFeeTotal = (float) $orders->sum('platform_fee_share');
            $refundsTotal = (float) \App\Models\Refund::query()->whereIn('order_id', $orders->pluck('id'))->where('status', 'processed')->sum('amount');
            $cashCollected = (float) $orders->where('payment_method', 'cash')->sum('total');

            $netAmount = $partyType === 'restaurant'
                ? round($grossSales - $commissionTotal - $refundsTotal - $cashCollected, 2)
                : round($orders->sum('driver_earning'), 2);

            $settlement = Settlement::create([
                'party_type' => $partyType,
                'party_id' => $partyId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'gross_sales' => $grossSales,
                'refunds_total' => $refundsTotal,
                'commission_total' => $commissionTotal,
                'delivery_fees_total' => $deliveryFeesTotal,
                'platform_fee_total' => $platformFeeTotal,
                // On a cash order the captain physically holds the service fee too —
                // it is inside `orders.total` — so it is netted off what the platform
                // owes through the existing formula. Nothing special is needed here.
                'cash_collected' => $cashCollected,
                'net_amount' => $netAmount,
                'status' => 'draft',
                'created_by' => $admin->id,
            ]);

            foreach ($orders as $order) {
                $settlement->items()->create([
                    'order_id' => $order->id,
                    'type' => $order->origin->value === 'restaurant_direct' ? 'direct_order_food_passthrough' : 'order',
                    'gross' => $order->subtotal,
                    'commission' => $order->commission_amount,
                    'delivery_fee' => $order->delivery_fee_share,
                    'platform_fee' => $order->platform_fee_share,
                    'driver_earning' => $order->driver_earning,
                    'net' => $partyType === 'restaurant'
                        ? round((float) $order->subtotal - (float) $order->commission_amount, 2)
                        : (float) $order->driver_earning,
                ]);
            }

            \App\Support\AuditLogger::record($admin, 'settlements.generate', $settlement, ['party_type' => $partyType, 'party_id' => $partyId]);

            return $settlement->load('items');
        });
    }
}
