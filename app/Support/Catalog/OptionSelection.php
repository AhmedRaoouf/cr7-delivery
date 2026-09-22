<?php

namespace App\Support\Catalog;

use App\Enums\OptionSelectionType;
use App\Exceptions\ApiException;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionGroup;
use Illuminate\Support\Collection;

/**
 * D-167 — the one reader of a product's option rules.
 *
 * Adding to the basket, editing a line, the basket view, checkout, the scheduled
 * release and reorder all asked their own version of «is this selection legal?», and
 * no two agreed: the add refused a missing size but took a switched-off one, the edit
 * checked nothing but ownership, and checkout checked nothing at all. Every path now
 * asks here.
 *
 * A group with no options is a **draft** (D-164): not shown to the customer, never
 * enforced, so a place can build a group before its first option.
 */
class OptionSelection
{
    /**
     * The groups a customer sees and is held to, options in their order.
     *
     * @return Collection<int, ProductOptionGroup>
     */
    public static function liveGroups(Product $product): Collection
    {
        $groups = $product->relationLoaded('optionGroups')
            ? $product->optionGroups
            : $product->optionGroups()->with('options')->get();

        return $groups
            ->filter(fn (ProductOptionGroup $group) => $group->options->isNotEmpty())
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(function (ProductOptionGroup $group) {
                $group->setRelation('options', $group->options->sortBy([['sort_order', 'asc'], ['id', 'asc']])->values());

                return $group;
            })
            ->values();
    }

    /** How many a group needs at least — `0` when it is optional. */
    public static function minimum(ProductOptionGroup $group): int
    {
        return $group->is_required ? max(1, (int) $group->min_select) : 0;
    }

    /** How many a group takes at most — always `1` for a single group. */
    public static function maximum(ProductOptionGroup $group): int
    {
        return $group->selection_type === OptionSelectionType::Single ? 1 : max(1, (int) $group->max_select);
    }

    /**
     * The problem with this selection, or null when it may be ordered.
     *
     * @param  array<int, int|string>  $optionIds
     * @return array{code: string, message: string, errors: array<string, mixed>, meta: array<string, mixed>}|null
     */
    public static function problem(Product $product, array $optionIds): ?array
    {
        $ids = collect($optionIds)->map(fn ($id) => (int) $id)->unique()->values();
        $groups = self::liveGroups($product);
        $known = $groups->flatMap(fn (ProductOptionGroup $group) => $group->options)->keyBy('id');

        $foreign = $ids->reject(fn (int $id) => $known->has($id))->values()->all();

        if ($foreign !== []) {
            return self::fail('validation_failed', 'أحد الخيارات المختارة غير صالح لهذا المنتج', ['option_ids' => $foreign]);
        }

        $off = $ids->filter(fn (int $id) => ! $known[$id]->is_available)->values()->all();

        if ($off !== []) {
            $names = collect($off)->map(fn (int $id) => $known[$id]->name_ar)->implode('، ');

            return self::fail('option_unavailable', "هذا الخيار لم يعد متاحًا: {$names}", meta: ['option_ids' => $off]);
        }

        foreach ($groups as $group) {
            $chosen = $group->options->pluck('id')->intersect($ids)->count();

            if ($chosen < self::minimum($group)) {
                return self::fail('validation_failed', "يجب اختيار {$group->name_ar}", ['option_group_id' => [$group->id]], ['option_group_id' => $group->id]);
            }

            $max = self::maximum($group);

            if ($chosen > $max) {
                return self::fail('validation_failed', "الحد الأقصى للاختيار في {$group->name_ar} هو {$max}", ['option_group_id' => [$group->id]], ['option_group_id' => $group->id]);
            }
        }

        return null;
    }

    /**
     * @param  array<int, int|string>  $optionIds
     *
     * @throws ApiException
     */
    public static function assertValid(Product $product, array $optionIds): void
    {
        $problem = self::problem($product, $optionIds);

        if ($problem !== null) {
            throw new ApiException($problem['code'], $problem['message'], 422, $problem['errors'], $problem['meta']);
        }
    }

    /**
     * D-166 — the least one unit can cost: the effective price plus the cheapest
     * available options every required group demands.
     */
    public static function fromPrice(Product $product, bool $base = false): float
    {
        $price = $base ? (float) $product->price : (float) ($product->discount_price ?? $product->price);

        foreach (self::liveGroups($product) as $group) {
            $need = self::minimum($group);

            if ($need === 0) {
                continue;
            }

            $price += (float) $group->options
                ->filter(fn (ProductOption $option) => $option->is_available)
                ->map(fn (ProductOption $option) => (float) $option->price_delta)
                ->sort()
                ->take($need)
                ->sum();
        }

        return round($price, 2);
    }

    /** @return array{code: string, message: string, errors: array<string, mixed>, meta: array<string, mixed>} */
    private static function fail(string $code, string $message, array $errors = [], array $meta = []): array
    {
        return ['code' => $code, 'message' => $message, 'errors' => $errors, 'meta' => $meta];
    }
}
