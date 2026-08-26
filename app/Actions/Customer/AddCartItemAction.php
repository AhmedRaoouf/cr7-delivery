<?php

namespace App\Actions\Customer;

use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** doc 08 §4 — POST /cart/items. */
class AddCartItemAction
{
    public function execute(User $user, array $data): Cart
    {
        return DB::transaction(function () use ($user, $data) {
            $cart = $user->cart()->firstOrCreate([]);

            $product = Product::query()->with('restaurant.servedZones')->findOrFail($data['product_id']);

            if (! $product->is_available || ! $product->restaurant->is_active) {
                throw new ApiException('product_unavailable', 'هذا المنتج لم يعد متاحًا', 422);
            }

            $existingRestaurantIds = $cart->items()->distinct()->pluck('restaurant_id');
            $maxRestaurants = (int) (Setting::query()->where('key', 'cart.max_restaurants')->value('value') ?? 3);

            if (! $existingRestaurantIds->contains($product->restaurant_id) && $existingRestaurantIds->count() >= $maxRestaurants) {
                throw new ApiException('too_many_restaurants', 'وصلت للحد الأقصى لعدد المطاعم في السلة الواحدة', 422, meta: [
                    'max' => $maxRestaurants,
                    'restaurants' => $existingRestaurantIds->values(),
                ]);
            }

            if ($cart->address) {
                $zoneId = $cart->address->service_zone_id;
                $servesZone = $product->restaurant->service_zone_id === $zoneId
                    || $product->restaurant->servedZones->contains('id', $zoneId);

                if (! $servesZone) {
                    throw new ApiException('restaurant_out_of_range', 'هذا المطعم لا يغطي عنوان التوصيل الحالي', 422, meta: [
                        'restaurant_name' => $product->restaurant->name_ar,
                    ]);
                }
            }

            $optionIds = $data['option_ids'] ?? [];
            $this->validateOptions($product, $optionIds);

            $item = $cart->items()->create([
                'restaurant_id' => $product->restaurant_id,
                'product_id' => $product->id,
                'quantity' => $data['quantity'] ?? 1,
                'note' => $data['note'] ?? null,
            ]);

            foreach ($optionIds as $optionId) {
                $item->options()->create(['product_option_id' => $optionId]);
            }

            return $cart->fresh();
        });
    }

    private function validateOptions(Product $product, array $optionIds): void
    {
        $groups = $product->optionGroups()->with('options')->get();
        $validOptionIds = $groups->flatMap(fn ($g) => $g->options->pluck('id'));

        $invalid = array_diff($optionIds, $validOptionIds->all());
        if ($invalid !== []) {
            throw new ApiException('validation_failed', 'أحد الخيارات المختارة غير صالح لهذا المنتج', 422, ['option_ids' => array_values($invalid)]);
        }

        foreach ($groups as $group) {
            $selectedInGroup = $group->options->pluck('id')->intersect($optionIds)->count();

            if ($group->is_required && $selectedInGroup < max(1, $group->min_select)) {
                throw new ApiException('validation_failed', "يجب اختيار {$group->name_ar}", 422, [
                    'option_group_id' => [$group->id],
                ]);
            }

            if ($selectedInGroup > $group->max_select) {
                throw new ApiException('validation_failed', "الحد الأقصى للاختيار في {$group->name_ar} هو {$group->max_select}", 422, [
                    'option_group_id' => [$group->id],
                ]);
            }
        }
    }
}
