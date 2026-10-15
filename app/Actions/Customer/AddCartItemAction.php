<?php

namespace App\Actions\Customer;

use App\Exceptions\ApiException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Support\BasketLimit;
use App\Support\Catalog\OptionSelection;
use Illuminate\Support\Facades\DB;

/** doc 08 §4 — POST /cart/items. */
class AddCartItemAction
{
    public function execute(User $user, array $data): Cart
    {
        return DB::transaction(function () use ($user, $data) {
            $cart = $user->cart()->firstOrCreate([]);

            $product = Product::query()->with('restaurant.servedZones')->findOrFail($data['product_id']);

            if (! $product->isOrderable() || ! $product->restaurant?->is_active) {
                throw new ApiException('product_unavailable', 'هذا المنتج لم يعد متاحًا', 422);
            }

            $existingRestaurantIds = $cart->items()->distinct()->pluck('restaurant_id');
            $maxRestaurants = BasketLimit::maxRestaurants();

            // D-114 — the panel's number, said in words («مكانين»). The code stays
            // `too_many_restaurants`: it is what the shipped app already switches on, and
            // the checkout blocker for an over-full basket uses the same one.
            if (! $existingRestaurantIds->contains($product->restaurant_id) && $existingRestaurantIds->count() >= $maxRestaurants) {
                throw new ApiException('too_many_restaurants', BasketLimit::sentence($maxRestaurants), 422, meta: [
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

            $optionIds = collect($data['option_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
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
                // The request caps one add; a merge would otherwise walk past the cap one
                // tap at a time.
                $maxQuantity = BasketLimit::maxQuantityPerLine();

                if ($existing->quantity + $quantity > $maxQuantity) {
                    $sentence = BasketLimit::quantitySentence($maxQuantity);

                    throw new ApiException('validation_failed', $sentence, 422, ['quantity' => [$sentence]]);
                }

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

    /** D-167 — through the one validator, so the add cannot disagree with checkout. */
    private function validateOptions(Product $product, array $optionIds): void
    {
        OptionSelection::assertValid($product, $optionIds);
    }
}
