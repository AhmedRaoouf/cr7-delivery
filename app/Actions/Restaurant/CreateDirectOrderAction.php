<?php

namespace App\Actions\Restaurant;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\Restaurant;
use App\Models\Setting;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\DispatchService;
use App\Support\OrderNumber;
use App\Support\Otp;
use App\Support\SettingsCatalog;
use App\Support\ReviewAccess;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §6.2 / doc 22 §4 / doc 16 D-47..D-49 — the restaurant buys a delivery, not a
 * marketplace order: subtotal = 0, no commission, no group/confirmation/payment gate,
 * no delivery code (the only order a captain can close alone).
 */
class CreateDirectOrderAction
{
    public function __construct(
        private readonly QuoteDirectOrderAction $quote,
        private readonly DispatchService $dispatch,
    ) {}

    public function execute(User $user, Restaurant $restaurant, array $data, string $idempotencyKey): Order
    {
        ReviewAccess::refuseRealWork($user);

        $existing = Order::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        if (! (bool) (Setting::query()->where('key', 'direct_orders.enabled')->value('value') ?? true) || ! $restaurant->direct_orders_enabled) {
            throw new ApiException('direct_orders_disabled', 'ميزة الطلبات المباشرة غير مفعّلة لهذا المطعم', 422);
        }

        $dailyCap = (int) (Setting::query()->where('key', 'direct_orders.daily_cap_per_restaurant')->value('value') ?? 20);
        $todayCount = $restaurant->orders()->where('origin', 'restaurant_direct')->whereDate('business_date', BusinessTime::date())->count();

        if ($todayCount >= $dailyCap) {
            throw new ApiException('direct_order_daily_cap_reached', 'تم الوصول للحد الأقصى اليومي للطلبات المباشرة', 422, meta: [
                'resets_at' => now()->endOfDay()->toIso8601String(),
            ]);
        }

        $duplicateRef = $restaurant->orders()->where('restaurant_order_ref', $data['restaurant_order_ref'])->whereDate('business_date', BusinessTime::date())->exists();
        if ($duplicateRef) {
            throw new ApiException('duplicate_restaurant_order_ref', 'رقم الطلب مستخدم بالفعل اليوم', 422);
        }

        $quote = $this->quote->execute(
            $restaurant,
            (float) $data['delivery_latitude'],
            (float) $data['delivery_longitude'],
            isset($data['zone_id']) ? (int) $data['zone_id'] : null,
        );

        return DB::transaction(function () use ($user, $restaurant, $data, $idempotencyKey, $quote) {
            $group = OrderGroup::create([
                'group_number' => OrderNumber::pending($idempotencyKey),
                'idempotency_key' => $idempotencyKey,
                'origin' => 'restaurant_direct',
                'user_id' => null,
                'city_id' => $restaurant->city_id,
                'service_zone_id' => $quote['service_zone_id'],
                'payment_method' => 'cash',
                'restaurant_count' => 1,
                'dispatch_mode' => 'driver_per_restaurant',
                'delivery_address_text' => $data['delivery_address_text'],
                'delivery_contact_phone' => $data['guest_customer_phone'],
                'delivery_latitude' => $data['delivery_latitude'],
                'delivery_longitude' => $data['delivery_longitude'],
                'max_distance_km' => $quote['distance_km'],
                'subtotal' => 0,
                'discount_amount' => 0,
                'delivery_base_fee' => $quote['delivery_fee'],
                'extra_stop_fee_total' => 0,
                'delivery_fee' => $quote['delivery_fee'],
                'platform_fee' => $quote['platform_fee'],
                'total' => $quote['total'],
                'rollup_status' => $data['ready_now'] ? 'ready' : 'preparing',
                'delivery_instructions' => $data['delivery_instructions'] ?? null,
                'placed_at' => now(),
                'confirmed_at' => now(),
                'confirmed_by' => 'system',
            ]);

            $groupNumber = OrderNumber::forGroup($group->id);
            $group->update(['group_number' => $groupNumber]);

            $status = $data['ready_now'] ? OrderStatus::Ready : OrderStatus::Preparing;
            $now = now();

            $order = Order::create([
                'order_group_id' => $group->id,
                'origin' => 'restaurant_direct',
                'created_by_restaurant_user_id' => $user->id,
                'guest_customer_name' => $data['guest_customer_name'],
                'guest_customer_phone' => $data['guest_customer_phone'],
                'guest_customer_phone_alt' => $data['guest_customer_phone_alt'] ?? null,
                'declared_items_text' => $data['declared_items_text'] ?? null,
                'declared_order_value' => $data['declared_order_value'] ?? 0,
                'food_payment' => $data['food_payment'],
                'order_number' => OrderNumber::forSubOrder($groupNumber, 1),
                'group_sequence' => 1,
                'idempotency_key' => $idempotencyKey,
                'user_id' => null,
                'restaurant_id' => $restaurant->id,
                'city_id' => $restaurant->city_id,
                'service_zone_id' => $quote['service_zone_id'],
                'status' => $status,
                'payment_method' => 'cash',
                'restaurant_name_snapshot' => $restaurant->name_ar,
                'delivery_address_text' => $data['delivery_address_text'],
                'delivery_address_snapshot' => [
                    'area' => $quote['service_zone_name'],
                    'zone_id' => $quote['service_zone_id'],
                    'street' => $data['delivery_address_text'],
                ],
                'delivery_contact_phone' => $data['guest_customer_phone'],
                'delivery_latitude' => $data['delivery_latitude'],
                'delivery_longitude' => $data['delivery_longitude'],
                'distance_km' => $quote['distance_km'],
                'subtotal' => 0,
                'discount_share' => 0,
                'delivery_fee_share' => $quote['delivery_fee'],
                // One restaurant by definition, so the share is the whole fee.
                'platform_fee_share' => $quote['platform_fee'],
                'total' => $quote['total'],
                'min_order_value_snapshot' => 0,
                'commission_rate_snapshot' => 0,
                'commission_amount' => 0,
                'restaurant_order_ref' => $data['restaurant_order_ref'],
                'business_date' => BusinessTime::date(),
                'prep_minutes' => $data['prep_minutes'] ?? null,
                'pickup_otp' => Otp::orderCode(),
                'delivery_otp' => null,
                // D-108 / D-49 — the pickup code follows the panel; a direct order never
                // has a delivery code.
                'pickup_code_required' => SettingsCatalog::bool('orders.pickup_code_required'),
                'delivery_code_required' => false,
                'delivery_instructions' => $data['delivery_instructions'] ?? null,
                'accepted_at' => $now,
                'confirmed_at' => $now,
                'preparing_at' => $data['ready_now'] ? null : $now,
                'prep_due_at' => $data['ready_now'] ? null : $now->clone()->addMinutes((int) ($data['prep_minutes'] ?? 15)),
                'ready_at' => $data['ready_now'] ? $now : null,
                'ready_source' => $data['ready_now'] ? 'restaurant' : null,
                'placed_at' => $now,
            ]);

            $order->statusHistory()->create([
                'from_status' => null,
                'to_status' => $status->value,
                'actor_type' => OrderStatusActorType::Restaurant,
                'actor_id' => $user->id,
            ]);

            if ($data['ready_now']) {
                $this->dispatch->dispatchRound($order->fresh(), 1);
            }

            return $order->fresh();
        });
    }
}
