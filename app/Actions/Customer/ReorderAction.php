<?php

namespace App\Actions\Customer;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\OrderGroup;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\BasketLimit;
use App\Support\Catalog\OptionSelection;
use Illuminate\Support\Facades\DB;

/**
 * doc 08 §4 — POST /orders/{group}/reorder, D-168.
 *
 * The order is a snapshot and the menu has moved on since, so every line is asked
 * again against **today's** menu. The old version copied option ids blindly: a deleted
 * size vanished without a word (leaving a line a required group refuses), a switched-
 * off extra went straight back into the basket, and reordering twice made two lines.
 */
class ReorderAction
{
    /**
     * @return array{
     *     added: list<int>,
     *     skipped: list<string>,
     *     skipped_items: list<array{product_id: int|null, name: string, reason: string}>,
     *     adjusted_items: list<array{cart_item_id: int, product_id: int, name: string, dropped_options: list<string>}>
     * }
     */
    public function execute(User $user, OrderGroup $orderGroup): array
    {
        return DB::transaction(function () use ($user, $orderGroup): array {
            $cart = $user->cart()->firstOrCreate([]);
            $result = ['added' => [], 'skipped' => [], 'skipped_items' => [], 'adjusted_items' => []];

            foreach ($orderGroup->orders()->with('items.options')->get() as $order) {
                foreach ($order->items as $item) {
                    $this->line($cart, $order->restaurant_id, $item, $result);
                }
            }

            return $result;
        });
    }

    private function line(Cart $cart, int $restaurantId, OrderItem $item, array &$result): void
    {
        $product = Product::query()->with(['restaurant', 'optionGroups.options'])->find($item->product_id);

        if ($product === null || ! $product->is_available || ! $product->restaurant?->is_active) {
            $this->skip($result, $item, 'product_unavailable');

            return;
        }

        $live = OptionSelection::liveGroups($product)
            ->flatMap(fn ($group) => $group->options)
            ->keyBy('id');

        $kept = [];
        $dropped = [];

        foreach ($item->options as $option) {
            $current = $option->product_option_id ? $live->get($option->product_option_id) : null;

            if ($current !== null && $current->is_available) {
                $kept[] = (int) $current->id;
            } else {
                $dropped[] = $option->option_name_ar_snapshot;
            }
        }

        if (OptionSelection::problem($product, $kept) !== null) {
            $this->skip($result, $item, 'options_unavailable');

            return;
        }

        $line = $this->mergeable($cart, $product->id, $kept, $item->note);

        if ($line !== null) {
            $line->update(['quantity' => min(BasketLimit::maxQuantityPerLine(), $line->quantity + $item->quantity)]);
        } else {
            $line = $cart->items()->create([
                'restaurant_id' => $restaurantId,
                'product_id' => $product->id,
                'quantity' => $item->quantity,
                'note' => $item->note,
            ]);

            foreach ($kept as $optionId) {
                $line->options()->create(['product_option_id' => $optionId]);
            }
        }

        $result['added'][] = $product->id;

        if ($dropped !== []) {
            $result['adjusted_items'][] = [
                'cart_item_id' => $line->id,
                'product_id' => $product->id,
                'name' => $item->product_name_ar_snapshot,
                'dropped_options' => $dropped,
            ];
        }
    }

    private function skip(array &$result, OrderItem $item, string $reason): void
    {
        $result['skipped'][] = $item->product_name_ar_snapshot;
        $result['skipped_items'][] = [
            'product_id' => $item->product_id,
            'name' => $item->product_name_ar_snapshot,
            'reason' => $reason,
        ];
    }

    /** The same dish, configured the same way, with the same note — {@see AddCartItemAction}. */
    private function mergeable(Cart $cart, int $productId, array $optionIds, ?string $note): ?CartItem
    {
        $wanted = collect($optionIds)->sort()->values()->all();

        return $cart->items()
            ->where('product_id', $productId)
            ->with('options')
            ->get()
            ->first(fn (CartItem $line): bool => ($line->note ?? null) === ($note ?? null)
                && $line->options->pluck('product_option_id')->map(fn ($id) => (int) $id)->sort()->values()->all() === $wanted);
    }
}
