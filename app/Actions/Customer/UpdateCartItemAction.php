<?php

namespace App\Actions\Customer;

use App\Models\CartItem;
use App\Support\Catalog\OptionSelection;
use Illuminate\Support\Facades\DB;

/** doc 08 §4 — PATCH /cart/items/{id}. */
class UpdateCartItemAction
{
    public function execute(CartItem $item, array $data): CartItem
    {
        return DB::transaction(function () use ($item, $data) {
            if (array_key_exists('quantity', $data)) {
                $item->update(['quantity' => $data['quantity']]);
            }

            if (array_key_exists('note', $data)) {
                $item->update(['note' => $data['note']]);
            }

            if (array_key_exists('option_ids', $data)) {
                $product = $item->product()->with('optionGroups.options')->first();
                $optionIds = collect($data['option_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();

                // D-167 — the edit is held to the same rules as the add. It used to
                // check only that the ids belonged to the dish, so `option_ids: []`
                // emptied a required size and a switched-off extra went straight in.
                OptionSelection::assertValid($product, $optionIds);

                $item->options()->delete();
                foreach ($optionIds as $optionId) {
                    $item->options()->create(['product_option_id' => $optionId]);
                }
            }

            return $item->fresh();
        });
    }
}
