<?php

namespace App\Actions\Customer;

use App\Exceptions\ApiException;
use App\Models\ScheduledOrder;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\CheckoutAddress;
use App\Support\CheckoutPricer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «اطلب في وقت لاحق» — the basket, with a time on it.
 *
 * Operator, 2026-09-07: «الكاستمر عايز الساعة كذا، عايز الأوردر ده الساعة ستة يبدأ
 * يتعمل… والساعة ستة يتبعت لوحده».
 *
 * It prices the basket **now** so the customer is shown a number and every blocker
 * (closed restaurant, minimum order, out-of-zone address) is caught while they are still
 * looking at the screen. That price is a snapshot for the dashboards, not a promise:
 * `ReleaseScheduledOrdersAction` re-prices at the appointed minute, because a menu can
 * change between 14:00 and 18:00 and charging yesterday's price for tonight's food is a
 * dispute nobody wins.
 */
class ScheduleOrderAction
{
    /** No sooner than this — a «scheduled» order 90 seconds out is just a slow order. */
    public const MIN_LEAD_MINUTES = 30;

    /** No further out than this. Beyond a week the menu is a different menu. */
    public const MAX_LEAD_DAYS = 7;

    public function __construct(private readonly CheckoutPricer $pricer) {}

    public function execute(User $user, array $data): ScheduledOrder
    {
        $when = Carbon::parse($data['scheduled_for']);

        if ($when->lt(now()->addMinutes(self::MIN_LEAD_MINUTES))) {
            throw new ApiException(
                'schedule_too_soon',
                'اختر موعدًا بعد '.self::MIN_LEAD_MINUTES.' دقيقة على الأقل من الآن',
                422,
            );
        }

        if ($when->gt(now()->addDays(self::MAX_LEAD_DAYS))) {
            throw new ApiException(
                'schedule_too_far',
                'لا يمكن جدولة الطلب لأكثر من '.self::MAX_LEAD_DAYS.' أيام',
                422,
            );
        }

        $cart = $user->cart()->firstOrCreate([]);

        if ($cart->items()->count() === 0) {
            throw new ApiException('cart_empty', 'السلة فارغة', 422);
        }

        $address = CheckoutAddress::find($user, $data['address_id']);

        $quote = $this->pricer->price($user, $cart, $address, $data['promo_code'] ?? null, $data['payment_method']);

        // Every blocker except «المطعم مغلق». A restaurant being shut at 14:00 is not a
        // reason to refuse an order for 18:00 — it is the whole point of scheduling one.
        // It is checked again at release, where it *is* a reason.
        $blockers = array_values(array_filter(
            $quote['blockers'],
            fn (array $blocker) => ! in_array($blocker['code'], ['restaurant_closed', 'restaurant_unavailable'], true),
        ));

        if ($blockers !== []) {
            throw CheckoutPricer::refusal($blockers);
        }

        $lines = $cart->items()->with(['product', 'restaurant', 'options.productOption'])->get();

        return DB::transaction(function () use ($user, $cart, $data, $address, $quote, $when, $lines): ScheduledOrder {
            $scheduled = ScheduledOrder::create([
                'reference' => self::reference(),
                'user_id' => $user->id,
                'address_id' => $address->id,
                'scheduled_for' => $when,
                'status' => ScheduledOrder::STATUS_SCHEDULED,
                'payload' => [
                    'address_id' => $address->id,
                    'payment_method' => $data['payment_method'],
                    'promo_code' => $data['promo_code'] ?? null,
                    'customer_note' => $data['customer_note'] ?? null,
                    'delivery_instructions' => $data['delivery_instructions'] ?? null,
                    // Every line, by id, so the release rebuilds exactly this basket
                    // rather than whatever the cart holds hours later.
                    'items' => $lines->map(fn ($item) => [
                        'restaurant_id' => $item->restaurant_id,
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'note' => $item->note,
                        'option_ids' => $item->options->pluck('product_option_id')->all(),
                    ])->all(),
                ],
                'basket_snapshot' => [
                    'restaurants' => collect($quote['groups'])->map(fn (array $group) => [
                        'restaurant_id' => $group['restaurant_id'],
                        'subtotal' => $group['subtotal'],
                    ])->all(),
                    'lines' => $lines->map(fn ($item) => [
                        'restaurant_id' => $item->restaurant_id,
                        'restaurant_name' => $item->restaurant?->name_ar,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product?->name_ar,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) ($item->product?->discount_price ?? $item->product?->price ?? 0),
                        'options' => $item->options->map(fn ($o) => $o->productOption?->name_ar)->filter()->values()->all(),
                    ])->all(),
                    'subtotal' => $quote['subtotal'],
                    'delivery_fee' => $quote['delivery_fee'],
                    'discount_amount' => $quote['discount_amount'],
                    'total' => $quote['total'],
                    'quoted_at' => now()->toIso8601String(),
                    'business_date' => BusinessTime::date(),
                ],
                'estimated_total' => $quote['total'],
                'restaurant_count' => $quote['restaurant_count'],
            ]);

            $scheduled->restaurants()->sync($lines->pluck('restaurant_id')->unique()->all());

            // The basket is spent, exactly as it would be by a normal checkout. Leaving
            // it filled means the customer schedules an order and then, still holding
            // the same basket, places it again by hand at half past two.
            $cart->items()->delete();
            $cart->update(['promo_code' => null]);

            return $scheduled;
        });
    }

    private static function reference(): string
    {
        return 'SCH-'.now()->format('ymd').'-'.Str::upper(Str::random(4));
    }
}
