<?php

namespace App\Actions\Customer;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusActorType;
use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderGroup;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\BusinessTime;
use App\Support\CheckoutPricer;
use App\Support\OrderNumber;
use App\Support\Otp;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §4 — POST /orders. doc 04 §7 — every order is created `pending`, cash or
 * online alike; the payment gate stays closed until every restaurant answers.
 */
class CreateOrderAction
{
    public function __construct(private readonly CheckoutPricer $pricer) {}

    /**
     * @param  Cart|null  $cart  the basket to place. Defaults to the customer's live one;
     *                           a scheduled order passes the scratch cart it was rebuilt
     *                           into, so releasing one at 18:00 cannot consume whatever
     *                           the customer happens to be shopping for at 18:00.
     */
    public function execute(User $user, array $data, string $idempotencyKey, ?Cart $cart = null): OrderGroup
    {
        $existing = OrderGroup::query()->where('idempotency_key', $idempotencyKey)->where('user_id', $user->id)->first();
        if ($existing) {
            return $existing->load(['orders.restaurant', 'payment']);
        }

        $cart ??= $user->cart()->firstOrCreate([]);
        if ($cart->items()->count() === 0) {
            throw new ApiException('cart_empty', 'السلة فارغة', 422);
        }

        $address = UserAddress::query()->where('user_id', $user->id)->findOrFail($data['address_id']);

        $quote = $this->pricer->price($user, $cart, $address, $data['promo_code'] ?? null, $data['payment_method']);

        if ($quote['blockers'] !== []) {
            $first = $quote['blockers'][0];
            throw new ApiException($first['code'], $first['message'], 422, meta: ['blockers' => $quote['blockers']]);
        }

        return DB::transaction(function () use ($user, $cart, $data, $idempotencyKey, $address, $quote) {
            $group = OrderGroup::create([
                'group_number' => OrderNumber::pending($idempotencyKey),
                'idempotency_key' => $idempotencyKey,
                'origin' => 'app',
                'user_id' => $user->id,
                'address_id' => $address->id,
                'city_id' => $address->city_id,
                'service_zone_id' => $address->service_zone_id,
                'payment_method' => $data['payment_method'],
                'restaurant_count' => $quote['restaurant_count'],
                'dispatch_mode' => $quote['dispatch_mode'],
                'delivery_address_text' => $this->composeAddressText($address),
                'delivery_contact_phone' => $address->contact_phone ?? $user->phone,
                'delivery_latitude' => $address->latitude,
                'delivery_longitude' => $address->longitude,
                'max_distance_km' => $quote['max_distance_km'],
                'delivery_pricing_rule_id' => $quote['delivery_pricing_rule_id'],
                'subtotal' => $quote['subtotal'],
                'discount_amount' => $quote['discount_amount'],
                'promo_code_id' => $quote['promo_code_id'],
                'delivery_base_fee' => $quote['delivery_base_fee'],
                'extra_stop_fee_total' => $quote['extra_stop_fee_total'],
                'delivery_fee' => $quote['delivery_fee'],
                // D-63 — snapshot. Changing `platform.order_fee` afterwards must not
                // re-price an order already placed.
                'platform_fee' => $quote['platform_fee'],
                // The balance this basket is collecting, snapshotted with the
                // rest of the money. It is cleared on the account below, in the
                // same transaction, so the fee is carried by exactly one order.
                'outstanding_fees' => $quote['outstanding_fees'],
                'total' => $quote['total'],
                'rollup_status' => 'pending',
                'customer_note' => $data['customer_note'] ?? null,
                'delivery_instructions' => $data['delivery_instructions'] ?? null,
                'placed_at' => now(),
            ]);

            $groupNumber = OrderNumber::forGroup($group->id);
            $group->update(['group_number' => $groupNumber]);

            $cartItemsByRestaurant = $cart->items()->with(['product', 'restaurant', 'options.productOption'])->get()->groupBy('restaurant_id');

            foreach ($quote['groups'] as $sequence => $groupQuote) {
                $restaurantId = $groupQuote['restaurant_id'];
                $lines = $cartItemsByRestaurant[$restaurantId];
                $restaurant = $lines->first()->restaurant;

                $groupSequence = $sequence + 1;
                $orderNumber = OrderNumber::forSubOrder($groupNumber, $groupSequence);

                $subOrder = Order::create([
                    'order_group_id' => $group->id,
                    'origin' => 'app',
                    'order_number' => $orderNumber,
                    'group_sequence' => $groupSequence,
                    'idempotency_key' => $idempotencyKey.'-'.$groupSequence,
                    'user_id' => $user->id,
                    'restaurant_id' => $restaurant->id,
                    'city_id' => $address->city_id,
                    'service_zone_id' => $address->service_zone_id,
                    'status' => OrderStatus::Pending,
                    'payment_method' => $data['payment_method'],
                    'restaurant_name_snapshot' => $restaurant->name_ar,
                    // DRV-13 names the person the captain is delivering to.
                    'customer_name_snapshot' => $user->name,
                    'delivery_address_text' => $this->composeAddressText($address),
                    'delivery_contact_phone' => $address->contact_phone ?? $user->phone,
                    'delivery_latitude' => $address->latitude,
                    'delivery_longitude' => $address->longitude,
                    'distance_km' => $groupQuote['distance_km'],
                    'delivery_pricing_rule_id' => $quote['delivery_pricing_rule_id'],
                    'subtotal' => $groupQuote['subtotal'],
                    'discount_share' => $groupQuote['discount_share'],
                    'delivery_fee_share' => $groupQuote['delivery_fee_share'],
                    'platform_fee_share' => $groupQuote['platform_fee_share'],
                    'total' => round(
                        $groupQuote['subtotal']
                        - $groupQuote['discount_share']
                        + $groupQuote['delivery_fee_share']
                        + $groupQuote['platform_fee_share'],
                        2,
                    ),
                    'min_order_value_snapshot' => $restaurant->min_order_value,
                    /*
                     * D-75 — there is no commission on food any more.
                     *
                     * Operator, 2026-09-11: «عمولة المنصة اللي هو الأدمن كلها ‪15‬٪ على أي
                     * order من فلوس الديليفري، مش عايز حاجة غيرها». Every restaurant's rate
                     * is zeroed by migration, and the snapshot is taken from the setting-free
                     * constant rather than the column so that a rate typed back into a row by
                     * hand cannot start charging places again without the decision being
                     * re-opened. The column and its permission stay for the history.
                     */
                    'commission_rate_snapshot' => 0,
                    'commission_amount' => 0,
                    // doc 05 §8 — pickup_otp is generated now; delivery_otp is generated later,
                    // at picked_up, so the customer's code cannot leak before the driver has the food.
                    'pickup_otp' => Otp::orderCode(),
                    'business_date' => BusinessTime::date(),
                    'customer_note' => $data['customer_note'] ?? null,
                    'delivery_instructions' => $data['delivery_instructions'] ?? null,
                    'placed_at' => now(),
                ]);

                foreach ($lines as $item) {
                    $unitPrice = (float) ($item->product->discount_price ?? $item->product->price);
                    $optionsTotal = $item->options->sum(fn ($o) => (float) $o->productOption->price_delta);

                    $orderItem = $subOrder->items()->create([
                        'product_id' => $item->product->id,
                        'product_name_ar_snapshot' => $item->product->name_ar,
                        'product_name_en_snapshot' => $item->product->name_en,
                        'product_image_snapshot' => $item->product->image,
                        'unit_price_snapshot' => $unitPrice,
                        'options_total' => $optionsTotal,
                        'quantity' => $item->quantity,
                        'line_total' => ($unitPrice + $optionsTotal) * $item->quantity,
                        'note' => $item->note,
                    ]);

                    foreach ($item->options as $option) {
                        $orderItem->options()->create([
                            'product_option_id' => $option->productOption->id,
                            'group_name_snapshot' => $option->productOption->group->name_ar,
                            'option_name_ar_snapshot' => $option->productOption->name_ar,
                            'option_name_en_snapshot' => $option->productOption->name_en,
                            'price_delta_snapshot' => $option->productOption->price_delta,
                        ]);
                    }
                }

                $subOrder->statusHistory()->create([
                    'from_status' => null,
                    'to_status' => OrderStatus::Pending->value,
                    'actor_type' => OrderStatusActorType::Customer,
                    'actor_id' => $user->id,
                ]);

                if ($group->promo_code_id) {
                    $group->promoCode->usages()->create([
                        'user_id' => $user->id,
                        'order_id' => $subOrder->id,
                        'discount_amount' => $groupQuote['discount_share'],
                    ]);
                }
            }

            if ($group->promo_code_id) {
                $group->promoCode->increment('used_count');
            }

            $group->payment()->create([
                'user_id' => $user->id,
                'method' => $data['payment_method'],
                'provider' => 'manual',
                'status' => 'pending',
                'amount' => 0,
            ]);

            // The balance is now this order's problem, not the account's. Cleared
            // inside the same transaction as the row that carries it, so there is
            // no window where a second checkout could charge it twice.
            // `forceFill`, because `outstanding_fees` is deliberately **not**
            // fillable: it is money the server owns, and a column a request
            // could mass-assign is a column a customer could zero.
            if ($quote['outstanding_fees'] > 0) {
                $user->forceFill(['outstanding_fees' => 0])->save();
            }

            $cart->items()->delete();

            return $group->load(['orders.restaurant', 'payment']);
        });
    }

    private function composeAddressText(UserAddress $address): string
    {
        return collect([$address->street, $address->building, $address->floor, $address->apartment, $address->landmark])
            ->filter()
            ->implode('، ');
    }
}
