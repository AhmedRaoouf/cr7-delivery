<?php

namespace App\Actions\Customer;

use App\Exceptions\ApiException;
use App\Models\CartItem;
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
                $optionIds = $data['option_ids'] ?? [];

                $validOptionIds = $product->optionGroups->flatMap(fn ($g) => $g->options->pluck('id'));
                $invalid = array_diff($optionIds, $validOptionIds->all());
                if ($invalid !== []) {
                    throw new ApiException('validation_failed', 'أحد الخيارات المختارة غير صالح لهذا المنتج', 422, ['option_ids' => array_values($invalid)]);
                }

                $item->options()->delete();
                foreach ($optionIds as $optionId) {
                    $item->options()->create(['product_option_id' => $optionId]);
                }
            }

            return $item->fresh();
        });
    }
}
