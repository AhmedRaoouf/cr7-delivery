<?php

namespace App\Support;

use App\Models\Cart;
use App\Models\City;
use App\Models\ServiceZone;
use App\Support\Catalog\OptionSelection;
use Illuminate\Http\Request;

/** doc 08 §4 — GET /cart response shape: always grouped by restaurant, even for one restaurant. */
class CartFormatter
{
    public static function format(Cart $cart, Request $request): array
    {
        $locale = Locale::current($request);
        // D-114 — through {@see BasketLimit}, which is the one reader of the limit. This
        // line used to query the row itself and fall back to **3** where the catalogue,
        // the seeder and `BasketLimit` all say **2**, so a deployment that had never
        // written the row let a basket hold three places here while every other screen
        // refused the third. A raw read with its own literal is a second source of truth.
        $maxRestaurants = BasketLimit::maxRestaurants();

        $items = $cart->items()->with(['product.optionGroups.options', 'restaurant', 'options.productOption.group'])->get();
        $itemsByRestaurant = $items->groupBy('restaurant_id');

        $basketIssues = [];
        $groups = $itemsByRestaurant->map(function ($restaurantItems) use ($locale, &$basketIssues) {
            $restaurant = $restaurantItems->first()->restaurant;
            $groupIssues = [];

            if (! $restaurant->is_active || ! $restaurant->isOpenNow()) {
                $groupIssues[] = ['code' => 'restaurant_closed', 'message' => 'المطعم مغلق حاليًا'];
            }

            $lines = $restaurantItems->map(function ($item) use ($locale, &$groupIssues) {
                $product = $item->product;
                $optionsTotal = $item->options->sum(fn ($o) => (float) $o->productOption->price_delta);
                $unitPrice = (float) ($product->discount_price ?? $product->price);
                $lineTotal = ($unitPrice + $optionsTotal) * $item->quantity;

                if (! $product->is_available) {
                    $groupIssues[] = ['code' => 'product_unavailable', 'message' => 'بعض المنتجات لم تعد متاحة', 'product_id' => $product->id];
                }

                // D-167 — the menu may have moved since the line was added: an option
                // switched off, a required size added. Said on the line, so the basket
                // can reopen that dish's sheet instead of checkout refusing blind.
                $optionIds = $item->options->pluck('product_option_id')->map(fn ($id) => (int) $id)->values()->all();
                $optionsProblem = OptionSelection::problem($product, $optionIds);

                if ($optionsProblem !== null) {
                    $groupIssues[] = [
                        'code' => 'options_invalid',
                        'message' => $optionsProblem['message'],
                        'product_id' => $product->id,
                        'cart_item_id' => $item->id,
                    ];
                }

                return [
                    'id' => $item->id,
                    'product_id' => $product->id,
                    'name' => $product->{"name_{$locale}"},
                    'image' => $product->image,
                    'unit_price' => $unitPrice,
                    'option_ids' => $optionIds,
                    'options' => $item->options->map(fn ($o) => [
                        'id' => $o->productOption->id,
                        'name' => $o->productOption->{"name_{$locale}"},
                        'price_delta' => (float) $o->productOption->price_delta,
                        'group_id' => $o->productOption->product_option_group_id,
                        'group_name' => $o->productOption->group?->{"name_{$locale}"},
                        'is_available' => (bool) $o->productOption->is_available,
                    ]),
                    'options_valid' => $optionsProblem === null,
                    'options_issue' => $optionsProblem === null ? null : [
                        'code' => $optionsProblem['code'],
                        'message' => $optionsProblem['message'],
                    ],
                    'options_total' => $optionsTotal,
                    'quantity' => $item->quantity,
                    'line_total' => $lineTotal,
                    'note' => $item->note,
                    'is_available' => $product->is_available,
                    'price_changed' => false,
                ];
            });

            $subtotal = $lines->sum('line_total');
            $meetsMinOrder = $subtotal >= (float) $restaurant->min_order_value;

            if (! $meetsMinOrder) {
                $groupIssues[] = ['code' => 'below_min_order', 'message' => 'الحد الأدنى للطلب لم يتحقق بعد'];
            }

            return [
                'restaurant' => [
                    'id' => $restaurant->id,
                    'name' => $restaurant->{"name_{$locale}"},
                    'logo' => $restaurant->logo,
                    'is_open_now' => $restaurant->isOpenNow(),
                ],
                'items' => $lines->values(),
                'subtotal' => $subtotal,
                'min_order_value' => (float) $restaurant->min_order_value,
                'meets_min_order' => $meetsMinOrder,
                'prep_minutes_estimate' => $restaurant->avg_prep_minutes,
                'issues' => $groupIssues,
            ];
        })->values();

        if ($groups->count() > $maxRestaurants) {
            $basketIssues[] = [
                'code' => 'too_many_restaurants',
                'message' => 'الحد الأقصى لعدد المطاعم في السلة الواحدة '.$maxRestaurants,
            ];
        }

        return [
            'groups' => $groups,
            'restaurant_count' => $groups->count(),
            'max_restaurants' => $maxRestaurants,
            'subtotal' => $groups->sum('subtotal'),
            'line_count' => $items->count(),
            'unit_count' => $items->sum('quantity'),
            // A late-cancellation fee this basket will collect. It has to be
            // visible here rather than only in the quote: a total that jumps by
            // 20 EGP between the basket and the checkout screen, with no line
            // naming it, reads as a bug or a swindle.
            'outstanding_fees' => (float) $cart->user->outstanding_fees,
            // D-81 — the delivery price of the area this basket is heading to.
            //
            // Operator, 2026-09-11: «لما اختار المنطقه … يجدد السعر علي اساسها».
            // The app computed `20 + 10 × (n − 1)` on the device with the zone
            // nowhere in it, so a basket bound for an area priced at 30 said 20
            // until the last screen of checkout. The quote is still what bills;
            // this is the same arithmetic from the same source, published early
            // enough that the basket cannot disagree with it.
            ...self::deliveryEstimate($cart),
            'issues' => $basketIssues,
        ];
    }

    /**
     * The base fee of the account's default address, with the extra-stop line
     * beside it so the client adds rather than invents.
     *
     * With no address saved yet — every first basket — it is the **cheapest
     * selectable area of the launch city**, which is the lowest true price on
     * the operator's list rather than a number chosen to look good.
     *
     * @return array<string, mixed>
     */
    private static function deliveryEstimate(Cart $cart): array
    {
        // Through the catalogue, so a stored `0` reads as zero rather than as «unset», and
        // the fallback is the declared default rather than a second copy of it here.
        $extraStopFee = SettingsCatalog::float('delivery.extra_stop_fee');

        $address = $cart->user->defaultAddress()->with('serviceZone')->first()
            ?? $cart->user->addresses()->with('serviceZone')->latest()->first();

        if ($address?->serviceZone !== null) {
            return [
                'delivery_address_id' => $address->id,
                'delivery_base_fee' => (float) $address->serviceZone->delivery_fee,
                'extra_stop_fee' => $extraStopFee,
            ];
        }

        $city = City::query()->where('is_active', true)->orderBy('sort_order')->first();
        $cheapest = $city === null
            ? null
            : ZoneLocator::selectableFor($city->id)->sortBy(fn (ServiceZone $zone) => (float) $zone->delivery_fee)->first();

        return [
            'delivery_address_id' => null,
            'delivery_base_fee' => (float) ($cheapest?->delivery_fee ?? 0),
            'extra_stop_fee' => $extraStopFee,
        ];
    }
}
