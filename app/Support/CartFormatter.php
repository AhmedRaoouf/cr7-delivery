<?php

namespace App\Support;

use App\Models\Cart;
use App\Models\Setting;
use Illuminate\Http\Request;

/** doc 08 §4 — GET /cart response shape: always grouped by restaurant, even for one restaurant. */
class CartFormatter
{
    public static function format(Cart $cart, Request $request): array
    {
        $locale = Locale::current($request);
        $maxRestaurants = (int) (Setting::query()->where('key', 'cart.max_restaurants')->value('value') ?? 3);

        $items = $cart->items()->with(['product', 'restaurant', 'options.productOption'])->get();
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

                return [
                    'id' => $item->id,
                    'product_id' => $product->id,
                    'name' => $product->{"name_{$locale}"},
                    'image' => $product->image,
                    'unit_price' => $unitPrice,
                    'options' => $item->options->map(fn ($o) => [
                        'id' => $o->productOption->id,
                        'name' => $o->productOption->{"name_{$locale}"},
                        'price_delta' => (float) $o->productOption->price_delta,
                    ]),
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
            'issues' => $basketIssues,
        ];
    }
}
