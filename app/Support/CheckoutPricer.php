<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\DeliveryPricingRule;
use App\Models\PromoCode;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\Catalog\LinePrice;
use App\Support\Catalog\OptionSelection;
use Illuminate\Support\Collection;

/**
 * doc 08 §4 — POST /checkout/quote. Also the pricing core CreateOrderAction reuses
 * so the numbers placed on an order are exactly the numbers the customer was quoted.
 * doc 16 D-63 — the platform service fee is added here and nowhere else, so the number
 * quoted is provably the number charged.
 * doc 16 §9 — multi-restaurant delivery fee = farthest restaurant's fee + extra_stop_fee
 * per extra stop; discount and delivery fee split pro-rata by subtotal, remainder to
 * the largest subtotal (doc 08 §4 checkout/quote note).
 */
class CheckoutPricer
{
    public function price(User $user, Cart $cart, UserAddress $address, ?string $promoCode, string $paymentMethod): array
    {
        $items = $cart->items()->with(['product.optionGroups.options', 'restaurant.serviceZone', 'options.productOption.group'])->get();
        $byRestaurant = $items->groupBy('restaurant_id');

        $blockers = [];

        $restaurantSubtotals = $byRestaurant->map(function (Collection $lines) {
            return $lines->sum(function ($item) {
                // doc 78 — one pricing rule for the basket, the quote and the order.
                ['unit' => $unitPrice, 'options_total' => $optionsTotal] = LinePrice::ofCartOptions($item->product, $item->options);

                return ($unitPrice + $optionsTotal) * $item->quantity;
            });
        });

        $subtotal = round($restaurantSubtotals->sum(), 2);
        $city = $address->city;

        $distances = $byRestaurant->keys()->mapWithKeys(function ($restaurantId) use ($byRestaurant, $address) {
            $restaurant = $byRestaurant[$restaurantId]->first()->restaurant;
            $distance = Geo::haversineKm((float) $restaurant->latitude, (float) $restaurant->longitude, (float) $address->latitude, (float) $address->longitude);

            return [$restaurantId => $distance];
        });

        $maxDistanceKm = $distances->max() ?? 0;
        $restaurantCount = $byRestaurant->count();

        $pricingRule = null;
        if ($city->pricing_type->value === 'distance_based') {
            $pricingRule = DeliveryPricingRule::query()
                ->where('city_id', $city->id)
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('service_zone_id')->orWhere('service_zone_id', $address->service_zone_id))
                ->where('min_distance_km', '<=', $maxDistanceKm)
                ->where('max_distance_km', '>=', $maxDistanceKm)
                ->orderByDesc('service_zone_id')
                ->first();
            $deliveryBaseFee = (float) ($pricingRule->fee ?? $address->serviceZone->delivery_fee ?? 0);
        } else {
            $deliveryBaseFee = (float) ($address->serviceZone->delivery_fee ?? 0);
        }

        $extraStopFee = (float) (Setting::query()->where('key', 'delivery.extra_stop_fee')->value('value') ?? 10);
        $extraStopFeeTotal = round($extraStopFee * max(0, $restaurantCount - 1), 2);

        $freeDeliveryOver = $pricingRule->free_delivery_over ?? $address->serviceZone->free_delivery_over ?? null;
        $freeDeliveryApplied = $freeDeliveryOver !== null && $subtotal >= (float) $freeDeliveryOver;

        // «خلي اول اورد للحساب توصيل مجاني» — operator, 2026-09-08. The platform's
        // own welcome offer, so it sits here beside the zone's own threshold and not
        // in the promo branch below: there is no code to type and nothing to enter
        // wrong. It is reported separately from `free_delivery_applied` because the
        // two are different sentences on `CUS-21` — «طلبك تعدّى الحد» and «أول طلب
        // لك» — and because only one of them can be true again next week.
        $firstOrderFree = FirstOrderPerk::appliesTo($user);

        $deliveryFee = $freeDeliveryApplied || $firstOrderFree
            ? 0.0
            : round($deliveryBaseFee + $extraStopFeeTotal, 2);

        // D-63 — the platform service fee. Charged **once per order group**, whatever
        // the basket spans: three restaurants pay it once, exactly like the delivery
        // fee (D-30..D-35). It is neither delivery nor commission, so `free_delivery`,
        // `free_delivery_over` and every discount below leave it alone on purpose.
        $platformFee = $restaurantCount === 0
            ? 0.0
            : round((float) (Setting::query()->where('key', 'platform.order_fee')->value('value') ?? 0), 2);

        $promo = null;
        $discountAmount = 0.0;
        $promoModel = null;
        $promoFreeDelivery = false;

        if ($promoCode) {
            [$promoModel, $promoValid, $promoReason] = $this->resolvePromo($promoCode, $user, $address, $subtotal, $byRestaurant->keys()->all());

            if ($promoModel && $promoValid) {
                if ($promoModel->type->value === 'free_delivery') {
                    $deliveryFee = 0.0;
                    $promoFreeDelivery = true;
                } else {
                    $discountAmount = $promoModel->type->value === 'percent'
                        ? round($subtotal * ((float) $promoModel->value / 100), 2)
                        : round((float) $promoModel->value, 2);

                    if ($promoModel->max_discount !== null) {
                        $discountAmount = min($discountAmount, (float) $promoModel->max_discount);
                    }
                    $discountAmount = min($discountAmount, $subtotal);
                }

                $promo = ['code' => $promoModel->code, 'title' => $promoModel->title_ar, 'valid' => true];
            } else {
                $promo = ['code' => $promoCode, 'title' => null, 'valid' => false, 'reason' => $promoReason];
            }
        }

        $groups = $byRestaurant->map(function (Collection $lines, $restaurantId) use ($restaurantSubtotals, $subtotal, $discountAmount, $deliveryFee, $platformFee, $distances) {
            $restaurant = $lines->first()->restaurant;
            $lineSubtotal = round($restaurantSubtotals[$restaurantId], 2);
            $share = $subtotal > 0 ? $lineSubtotal / $subtotal : 0;

            return [
                'restaurant_id' => $restaurant->id,
                'restaurant_name' => $restaurant->name_ar,
                'distance_km' => round($distances[$restaurantId], 2),
                'subtotal' => $lineSubtotal,
                'discount_share' => round($discountAmount * $share, 2),
                'delivery_fee_share' => round($deliveryFee * $share, 2),
                'platform_fee_share' => round($platformFee * $share, 2),
                'meets_min_order' => $lineSubtotal >= (float) $restaurant->min_order_value,
                'eta_minutes_min' => $restaurant->serviceZone ? max(5, $restaurant->serviceZone->avg_delivery_minutes - 10) : null,
                'eta_minutes_max' => $restaurant->serviceZone ? $restaurant->serviceZone->avg_delivery_minutes + 10 : null,
                'is_open_now' => $restaurant->isOpenNow(),
            ];
        })->values();

        // Rounding remainder goes to the largest subtotal (doc 08 §4).
        $groups = $this->assignRoundingRemainder($groups, 'discount_share', $discountAmount);
        $groups = $this->assignRoundingRemainder($groups, 'delivery_fee_share', $deliveryFee);
        $groups = $this->assignRoundingRemainder($groups, 'platform_fee_share', $platformFee);

        foreach ($groups as $group) {
            if (! $group['meets_min_order']) {
                $blockers[] = ['code' => 'below_min_order', 'message' => 'الحد الأدنى للطلب لم يتحقق', 'restaurant_id' => $group['restaurant_id']];
            }
            if (! $group['is_open_now']) {
                $blockers[] = ['code' => 'restaurant_closed', 'message' => 'المطعم مغلق حاليًا', 'restaurant_id' => $group['restaurant_id']];
            }
        }

        if ($restaurantCount === 0) {
            $blockers[] = ['code' => 'cart_empty', 'message' => 'السلة فارغة'];
        }

        // D-167 — the basket is re-read against today's menu. A dish switched off, an
        // option switched off, or a required size added after the line went in used to
        // reach the order unchallenged; each is refused by name and line, so the app can
        // reopen that dish rather than guess.
        foreach ($items as $item) {
            if (! $item->product->isOrderable()) {
                $blockers[] = [
                    'code' => 'product_unavailable',
                    'message' => "لم يعد متاحًا: {$item->product->name_ar}",
                    'product_id' => $item->product_id,
                    'cart_item_id' => $item->id,
                ];

                continue;
            }

            $problem = OptionSelection::problem(
                $item->product,
                $item->options->pluck('product_option_id')->map(fn ($id) => (int) $id)->all(),
            );

            if ($problem !== null) {
                $blockers[] = [
                    'code' => 'options_invalid',
                    'message' => "{$item->product->name_ar}: {$problem['message']}",
                    'product_id' => $item->product_id,
                    'cart_item_id' => $item->id,
                ];
            }
        }

        // D-114 — a basket filled before the operator lowered the limit is not trimmed
        // behind the customer's back: it is refused by name, and the basket screen asks
        // them to remove a place.
        $maxRestaurants = BasketLimit::maxRestaurants();

        if ($restaurantCount > $maxRestaurants) {
            $blockers[] = [
                'code' => 'too_many_restaurants',
                'message' => BasketLimit::sentence($maxRestaurants),
                'max' => $maxRestaurants,
            ];
        }

        // D-111 — an address may be saved incomplete (the first-launch address usually
        // has no street) and may not be ordered to. Said here, with the missing fields
        // named, so the customer is told what to add before the order is taken rather
        // than the captain finding out at the door.
        $missing = AddressCompleteness::missing($address);

        if ($missing !== []) {
            $blockers[] = [
                'code' => 'address_incomplete',
                'message' => AddressCompleteness::sentence($missing),
                'address_id' => $address->id,
                'missing_fields' => $missing,
            ];
        }

        // D-109 — a method the operator switched off is refused by name. The request
        // rules still accept all three values: the app that sent a stale one is told
        // *why*, in the customer's words, instead of being told the field is invalid.
        if (! PaymentMethods::isEnabled($paymentMethod)) {
            $blockers[] = [
                'code' => 'payment_method_disabled',
                'message' => PaymentMethods::disabledMessage(),
                'payment_method' => $paymentMethod,
            ];
        }

        if ($paymentMethod !== 'cash' && $user->cash_only_until && $user->cash_only_until->isFuture()) {
            $blockers[] = ['code' => 'cash_only_restricted', 'message' => 'حسابك مقيّد بالدفع نقدًا فقط حاليًا'];
        }

        // «رسوم إلغاء سابقة» — a late-cancellation fee from an earlier basket that
        // had no refund to come out of (`LateCancellationFee`). It is collected
        // by the next order rather than chased separately, and it is added to
        // the total *after* every discount: a promo code is a reason to charge
        // less for food, not a reason to forgive a fee.
        $outstandingFees = round((float) $user->outstanding_fees, 2);

        $total = round($subtotal - $discountAmount + $deliveryFee + $platformFee + $outstandingFees, 2);

        // Operator, 2026-09-18 — «فوق الالف جنيه لازم تحويل». Compared with the total the
        // customer is about to be charged, all of it: every place in the basket, the
        // delivery and service fees, a carried fee, less the discount. Only for a cash
        // basket the operator still takes cash for — a switched-off cash is already
        // `payment_method_disabled` above, and one refusal is enough.
        if ($paymentMethod === PaymentMethod::Cash->value && PaymentMethods::isEnabled(PaymentMethod::Cash) && ! CashLimit::allows($total)) {
            $blockers[] = CashLimit::blocker($total, $user);
        }

        return [
            'restaurant_count' => $restaurantCount,
            'groups' => $groups,
            'max_distance_km' => round($maxDistanceKm, 3),
            'delivery_base_fee' => round($deliveryBaseFee, 2),
            'extra_stop_fee' => $extraStopFee,
            'extra_stop_fee_total' => $extraStopFeeTotal,
            'delivery_fee' => $deliveryFee,
            'platform_fee' => $platformFee,
            'outstanding_fees' => $outstandingFees,
            'delivery_pricing_rule_id' => $pricingRule?->id,
            'free_delivery_applied' => $freeDeliveryApplied,
            'first_order_free_applied' => $firstOrderFree,
            // D-105 — why the fee is zero, so a zero never reads as a defect: the
            // account's first order, the area's threshold, or a free-delivery code.
            // The first order wins when several are true — it is the one the
            // captain's card and the customer's summary name.
            'free_delivery_reason' => match (true) {
                $firstOrderFree => 'first_order',
                $freeDeliveryApplied => 'threshold',
                $promoFreeDelivery => 'promo',
                default => null,
            },
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'promo' => $promo,
            'promo_code_id' => $promoModel?->id,
            'total' => $total,
            'dispatch_mode' => 'driver_per_restaurant',
            'separate_deliveries_warning' => $restaurantCount > 1,
            'eta_minutes_min' => $groups->min('eta_minutes_min'),
            'eta_minutes_max' => $groups->max('eta_minutes_max'),
            // D-109 — the switched-on methods only, each with its own destination.
            // `enabled` stays on every row for the clients that filtered on it;
            // `available` and `reason` say whether *this* basket may use it
            // (`above_cash_limit`, `cash_only_restricted`), so no client re-prices.
            'payment_methods' => PaymentMethods::offeredFor($total, $user),
            // `payment.cash_max_total` — cash pays a basket up to this, 0 = no limit.
            'cash_max_total' => CashLimit::max(),
            'blockers' => $blockers,
        ];
    }

    /**
     * The 422 `POST /orders` and `POST /scheduled-orders` answer with: the first blocker's
     * code and sentence, every blocker in `meta.blockers` — and, for `cash_not_allowed`,
     * the limit and the total it was compared with beside them.
     *
     * @param  non-empty-list<array<string, mixed>>  $blockers
     */
    public static function refusal(array $blockers): ApiException
    {
        $first = $blockers[0];
        $meta = ['blockers' => $blockers];

        if ($first['code'] === CashLimit::CODE) {
            $meta += ['cash_max_total' => $first['cash_max_total'], 'total' => $first['total']];
        }

        return new ApiException($first['code'], $first['message'], 422, meta: $meta);
    }

    /** doc 08 §4 — POST /promo/validate: strict, throws on any invalidity. */
    public function validatePromo(User $user, Cart $cart, UserAddress $address, string $code): PromoCode
    {
        $items = $cart->items()->with(['product', 'options.productOption.group'])->get();
        // doc 78 — the same subtotal the quote charges: options included. This read the
        // bare dish, so a promo's minimum was judged on a smaller basket than the bill.
        $subtotal = round($items->sum(function ($item) {
            ['unit' => $unit, 'options_total' => $extras] = LinePrice::ofCartOptions($item->product, $item->options);

            return ($unit + $extras) * $item->quantity;
        }), 2);
        $restaurantIds = $items->pluck('restaurant_id')->unique()->values()->all();

        [$promo, $valid, $reason] = $this->resolvePromo($code, $user, $address, $subtotal, $restaurantIds);

        if (! $promo) {
            throw new ApiException('promo_invalid', 'كود الخصم غير صحيح', 422);
        }

        if (! $valid) {
            $messages = [
                'promo_expired' => 'انتهت صلاحية كود الخصم',
                'promo_usage_exceeded' => 'تم استخدام كود الخصم للحد الأقصى المسموح',
                'promo_no_longer_applicable' => 'كود الخصم لا ينطبق على مطاعم السلة الحالية',
                'below_min_order' => 'السلة لم تصل بعد للحد الأدنى لاستخدام الكود',
                'promo_invalid' => 'كود الخصم غير صالح لهذا الطلب',
            ];

            throw new ApiException($reason, $messages[$reason] ?? 'كود الخصم غير صالح', 422);
        }

        return $promo;
    }

    /** @return array{0: ?PromoCode, 1: bool, 2: ?string} */
    private function resolvePromo(string $code, User $user, UserAddress $address, float $subtotal, array $restaurantIds): array
    {
        $promo = PromoCode::query()->where('code', $code)->where('is_active', true)->first();

        if (! $promo) {
            return [null, false, 'promo_invalid'];
        }

        if (($promo->starts_at && $promo->starts_at->isFuture()) || ($promo->ends_at && $promo->ends_at->isPast())) {
            return [$promo, false, 'promo_expired'];
        }

        if ($subtotal < (float) $promo->min_order_value) {
            return [$promo, false, 'below_min_order'];
        }

        if ($promo->usage_limit_total !== null && $promo->used_count >= $promo->usage_limit_total) {
            return [$promo, false, 'promo_usage_exceeded'];
        }

        if ($promo->usage_limit_per_user !== null) {
            $usedByUser = $promo->usages()->where('user_id', $user->id)->count();
            if ($usedByUser >= $promo->usage_limit_per_user) {
                return [$promo, false, 'promo_usage_exceeded'];
            }
        }

        if ($promo->first_order_only && $user->orderGroups()->exists()) {
            return [$promo, false, 'promo_usage_exceeded'];
        }

        if ($promo->restrict_city_ids && ! in_array($address->city_id, $promo->restrict_city_ids, true)) {
            return [$promo, false, 'promo_invalid'];
        }

        if ($promo->restrict_zone_ids && ! in_array($address->service_zone_id, $promo->restrict_zone_ids, true)) {
            return [$promo, false, 'promo_invalid'];
        }

        if ($promo->restrict_restaurant_ids && array_diff($restaurantIds, $promo->restrict_restaurant_ids) === $restaurantIds) {
            // none of the basket restaurants are in the allow-list
            return [$promo, false, 'promo_no_longer_applicable'];
        }

        return [$promo, true, null];
    }

    private function assignRoundingRemainder(Collection $groups, string $field, float $total): Collection
    {
        if ($groups->isEmpty()) {
            return $groups;
        }

        $sum = round($groups->sum($field), 2);
        $remainder = round($total - $sum, 2);

        if (abs($remainder) < 0.01) {
            return $groups;
        }

        $largestId = $groups->sortByDesc('subtotal')->first()['restaurant_id'];

        return $groups->map(function ($group) use ($largestId, $field, $remainder) {
            if ($group['restaurant_id'] === $largestId) {
                $group[$field] = round($group[$field] + $remainder, 2);
            }

            return $group;
        });
    }
}
