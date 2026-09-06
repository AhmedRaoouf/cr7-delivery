<?php

namespace App\Actions\Customer;

use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartItem;
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

            $quantity = max(1, (int) ($data['quantity'] ?? 1));
            $note = $data['note'] ?? null;

            /*
             * The same dish, configured the same way, is **one line with a count** — not a
             * new row per tap.
             *
             * `create()` unconditionally was how «كانز» tapped three times became three
             * separate lines of one each: the basket read as three items, the stepper on
             * each said 1, and removing «the can» removed a third of it. Every delivery app
             * merges here, and so does the app's own `+` on a line that already exists —
             * so the two paths now agree.
             *
             * What counts as "the same" is product + option set + note. A different size or
             * a «بدون بصل» note is a different thing to cook, so it stays its own line.
             */
            $existing = $this->findMergeable($cart, $product->id, $optionIds, $note);

            if ($existing !== null) {
                $existing->increment('quantity', $quantity);

                return $cart->fresh();
            }

            $item = $cart->items()->create([
                'restaurant_id' => $product->restaurant_id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'note' => $note,
            ]);

            foreach ($optionIds as $optionId) {
                $item->options()->create(['product_option_id' => $optionId]);
            }

            return $cart->fresh();
        });
    }

    /**
     * The line this add should fold into, or null if it is a new configuration.
     *
     * @param  array<int, int|string>  $optionIds
     */
    private function findMergeable(Cart $cart, int $productId, array $optionIds, ?string $note): ?CartItem
    {
        $wanted = collect($optionIds)->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $cart->items()
            ->where('product_id', $productId)
            ->with('options')
            ->get()
            ->first(function (CartItem $item) use ($wanted, $note): bool {
                if (($item->note ?? null) !== ($note ?? null)) {
                    return false;
                }

                $have = $item->options->pluck('product_option_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

                return $have === $wanted;
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
