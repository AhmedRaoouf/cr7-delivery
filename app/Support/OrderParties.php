<?php

namespace App\Support;

use App\Models\Driver;
use App\Models\Order;

/**
 * D-101 — everyone on an order sees everyone on it, in one shape.
 *
 * Operator, 2026-09-13: «المطعم بيشوف كل تفاصيل ال order وتفاصيل العميل وتفاصيل الكابتن
 * بص كل حاجه حرفيا وكذلك الكابتن وكذلك اليوزر». Four payloads served an order to its
 * three parties and each told a different story: the place was handed the captain's
 * **national id, both balances and earning rule** because the relation was serialised
 * raw, while the captain's job screen could not print the customer's name on any
 * marketplace order because it read a guest field only direct orders fill.
 *
 * So the parties are built here, once, and every payload carries the same three blocks:
 * `customer`, `place`, `captain` — plus `money`, which is the one block whose contents
 * depend on who is asking (doc 62 §2.2). What a party is entitled to about the others
 * is their name, a number to dial, where they are and how to recognise them; what it is
 * never entitled to is another party's internals.
 */
final class OrderParties
{
    /**
     * The eager loads every party payload needs — with the captain limited to the
     * columns a party may see. Loading `driver.user` whole is how the place came to
     * receive a captain's national id and both his balances.
     *
     * @return array<int|string, mixed>
     */
    public static function relations(): array
    {
        return [
            'driver' => fn ($q) => $q->select(['id', 'user_id', 'vehicle_type', 'vehicle_plate', 'rating_avg', 'current_lat', 'current_lng', 'location_updated_at']),
            'driver.user' => fn ($q) => $q->select(['id', 'name', 'phone', 'avatar']),
            'restaurant',
            'serviceZone:id,name_ar,name_en',
            'city:id,name_ar,name_en',
            'orderGroup.payment',
        ];
    }

    /**
     * The order's own row, plus the three parties and the money — for a viewer who is
     * not the admin (whose payload carries every relation already).
     *
     * @return array<string, mixed>
     */
    public static function present(Order $order, string $viewer): array
    {
        $parties = self::for($order, $viewer);

        // The group and the place ride in the blocks above, trimmed; serialised whole they
        // would carry the group's idempotency key and the place's commission rate.
        foreach (['orderGroup', 'restaurant', 'serviceZone', 'city'] as $relation) {
            $order->unsetRelation($relation);
        }

        return $order->toArray() + $parties + [
            'pickup_code_required' => (bool) ($order->pickup_code_required ?? true),
            'delivery_code_required' => ! $order->isDirect() && (bool) ($order->delivery_code_required ?? true),
        ];
    }

    /**
     * The captain's job payload. It keeps the two relations the shipped app reads —
     * `restaurant` and `order_group.address` — limited to what a captain may see, beside
     * the three blocks the next build reads instead.
     *
     * @return array<string, mixed>
     */
    public static function presentForCaptain(Order $order): array
    {
        $order->loadMissing([
            'items.options',
            'orderGroup:id,address_id,group_number,payment_method,outstanding_fees',
            'orderGroup.address:id,label,title,street,building,floor,apartment,landmark,latitude,longitude,contact_phone',
            'orderGroup.payment:id,order_group_id,status',
            'serviceZone:id,name_ar,name_en',
            'city:id,name_ar,name_en',
            'user:id,name,phone',
        ]);

        $order->setRelation('restaurant', $order->restaurant()->select([
            'id', 'name_ar', 'name_en', 'logo', 'phone', 'whatsapp', 'address', 'latitude', 'longitude', 'service_zone_id',
        ])->first());

        $parties = self::for($order, 'driver');

        foreach (['serviceZone', 'city', 'user'] as $relation) {
            $order->unsetRelation($relation);
        }

        return $order->toArray() + $parties + [
            'pickup_code_required' => (bool) ($order->pickup_code_required ?? true),
            'delivery_code_required' => ! $order->isDirect() && (bool) ($order->delivery_code_required ?? true),
            'paid_at_pickup' => $parties['money']['paid_at_pickup'],
            'cash_to_collect' => $parties['money']['cash_to_collect'],
        ];
    }

    /** @return array{customer: array<string, mixed>, place: array<string, mixed>|null, captain: array<string, mixed>|null, money: array<string, mixed>} */
    public static function for(Order $order, string $viewer): array
    {
        return [
            'customer' => self::customer($order),
            'place' => self::place($order),
            'captain' => self::captain($order->driver),
            'money' => self::money($order, $viewer),
        ];
    }

    /** @return array<string, mixed> */
    public static function customer(Order $order): array
    {
        $snapshot = is_array($order->delivery_address_snapshot) ? $order->delivery_address_snapshot : [];
        $isDirect = $order->isDirect();

        return [
            'name' => $isDirect
                ? $order->guest_customer_name
                : ($order->customer_name_snapshot ?? $order->user?->name),
            'phone' => $isDirect
                ? ($order->guest_customer_phone ?? $order->delivery_contact_phone)
                : ($order->delivery_contact_phone ?? $order->user?->phone),
            'phone_alt' => $isDirect ? $order->guest_customer_phone_alt : null,
            'address' => [
                'text' => $order->delivery_address_text,
                'label' => $snapshot['label'] ?? null,
                'title' => $snapshot['title'] ?? null,
                'area' => $snapshot['area'] ?? $order->serviceZone?->name_ar,
                'city' => $snapshot['city'] ?? $order->city?->name_ar,
                'street' => $snapshot['street'] ?? null,
                'building' => $snapshot['building'] ?? null,
                'floor' => $snapshot['floor'] ?? null,
                'apartment' => $snapshot['apartment'] ?? null,
                'landmark' => $snapshot['landmark'] ?? null,
                'latitude' => $order->delivery_latitude === null ? null : (float) $order->delivery_latitude,
                'longitude' => $order->delivery_longitude === null ? null : (float) $order->delivery_longitude,
            ],
            'note' => $order->customer_note,
            'delivery_instructions' => $order->delivery_instructions,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function place(Order $order): ?array
    {
        $restaurant = $order->restaurant;

        if ($restaurant === null) {
            return null;
        }

        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name_ar,
            'name_en' => $restaurant->name_en,
            'phone' => $restaurant->phone,
            'whatsapp' => $restaurant->whatsapp,
            'address' => $restaurant->address,
            'latitude' => $restaurant->latitude === null ? null : (float) $restaurant->latitude,
            'longitude' => $restaurant->longitude === null ? null : (float) $restaurant->longitude,
            'logo' => $restaurant->logo,
        ];
    }

    /** @return array<string, mixed>|null */
    public static function captain(?Driver $driver): ?array
    {
        if ($driver === null) {
            return null;
        }

        $stale = (int) (SettingsCatalog::get('dispatch.location_stale_seconds') ?? 90);
        $fix = $driver->hasFix();

        return [
            'id' => $driver->id,
            'name' => $driver->user?->name,
            'phone' => $driver->user?->phone,
            'avatar' => $driver->user?->avatar,
            'vehicle_type' => $driver->vehicle_type?->value,
            'vehicle_label' => $driver->vehicle_type?->labelAr(),
            'vehicle_plate' => $driver->vehicle_plate,
            'rating_avg' => $driver->rating_avg === null ? null : round((float) $driver->rating_avg, 2),
            'latitude' => $fix ? (float) $driver->current_lat : null,
            'longitude' => $fix ? (float) $driver->current_lng : null,
            'location_updated_at' => $driver->location_updated_at?->toIso8601String(),
            'is_location_stale' => $driver->location_updated_at === null
                || $driver->location_updated_at->diffInSeconds(now()) >= $stale,
        ];
    }

    /**
     * doc 62 §2.2 — the order's money, trimmed to what this viewer is entitled to.
     *
     * @return array<string, mixed>
     */
    public static function money(Order $order, string $viewer): array
    {
        $group = $order->orderGroup;

        $money = [
            'payment_method' => $order->payment_method?->value,
            'payment_status' => $group?->payment?->status,
            'subtotal' => round((float) $order->subtotal, 2),
            'discount' => round((float) $order->discount_share, 2),
            'delivery_fee' => round((float) $order->delivery_fee_share, 2),
            'service_fee' => round((float) $order->platform_fee_share, 2),
            'outstanding_fees' => (int) $order->group_sequence === 1 ? round((float) ($group?->outstanding_fees ?? 0), 2) : 0.0,
            'total' => round((float) $order->total, 2),
            'declared_order_value' => $order->isDirect() ? round((float) $order->declared_order_value, 2) : null,
            'cash_to_collect' => $order->cashToCollect(),
            'free_delivery_reason' => $order->free_delivery_reason,
        ];

        if ($viewer !== 'customer') {
            $money['restaurant_amount'] = $order->restaurant_amount;
            $money['paid_at_pickup'] = $order->place_paid_amount !== null
                ? round((float) $order->place_paid_amount, 2)
                : $order->placePaidAtPickup();
            // What the platform owes the place for this order — the online food money,
            // which its statement pays. Zero on a cash order: the captain paid at pickup.
            $money['platform_owes_place'] = $order->payment_method?->value === 'cash' || $order->isDirect()
                ? 0.0
                : $order->restaurant_amount;
        }

        if (in_array($viewer, ['driver', 'admin'], true)) {
            $money['driver_earning'] = round((float) $order->driver_earning, 2);
        }

        if ($viewer === 'admin') {
            $money['platform_delivery_margin'] = round((float) $order->platform_delivery_margin, 2);
            $money['commission_amount'] = round((float) $order->commission_amount, 2);
        }

        return $money;
    }
}
