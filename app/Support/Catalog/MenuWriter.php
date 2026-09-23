<?php

namespace App\Support\Catalog;

use App\Exceptions\ApiException;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Support\Http\PublicCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Doc 77 — the menu writes the place and the admin share: deleting a category without
 * orphaning its dishes, deleting many dishes at once, and ordering categories and the
 * dishes inside one. Both controllers call these, so the two surfaces cannot drift.
 */
final class MenuWriter
{
    /**
     * A category is soft-deleted, so the `cascadeOnDelete` on `products.menu_category_id`
     * never fires: before doc 77 its dishes stayed behind, invisible on the tab strip and
     * still listed, orderable, under «كل الأصناف». Now a category that still holds dishes
     * is refused unless the caller says where they go: `move_to` another category of the
     * same place, or `delete_products` with them.
     */
    public static function deleteCategory(Restaurant $restaurant, MenuCategory $category, Request $request): array
    {
        $validated = $request->validate([
            'move_to' => ['nullable', 'integer'],
            'delete_products' => ['nullable', 'boolean'],
        ]);

        $count = $category->products()->count();
        $moveTo = $validated['move_to'] ?? null;
        $deleteProducts = (bool) ($validated['delete_products'] ?? false);

        if ($count > 0 && $moveTo === null && ! $deleteProducts) {
            throw new ApiException('category_not_empty', "القسم يحتوي على {$count} صنف — انقلها إلى قسم آخر أو احذفها معه", 409, meta: [
                'products_count' => $count,
            ]);
        }

        $target = null;

        if ($count > 0 && $moveTo !== null) {
            $target = $restaurant->menuCategories()->whereKey($moveTo)->first();

            if ($target === null || $target->is($category)) {
                throw new ApiException('validation_failed', 'اختر قسمًا آخر من أقسام المكان نفسه', 422, [
                    'move_to' => ['اختر قسمًا آخر من أقسام المكان نفسه'],
                ]);
            }
        }

        DB::transaction(function () use ($category, $count, $target): void {
            if ($count > 0 && $target !== null) {
                $category->products()->update(['menu_category_id' => $target->id]);
            } elseif ($count > 0) {
                // One by one, so each dish's observer runs (price band, caches).
                $category->products()->get()->each->delete();
            }

            $category->delete();
        });

        PublicCache::bump('catalog');

        return [
            'moved' => $target !== null ? $count : 0,
            'deleted_products' => $target === null ? $count : 0,
        ];
    }

    /** `ids` of this place's dishes; any id that is not one is ignored, not an error. */
    public static function deleteProducts(Restaurant $restaurant, Request $request): int
    {
        $ids = self::ids($request);
        $products = $restaurant->products()->whereIn('id', $ids)->get();

        DB::transaction(fn () => $products->each->delete());
        PublicCache::bump('catalog');

        return $products->count();
    }

    public static function setAvailability(Restaurant $restaurant, Request $request): int
    {
        $ids = self::ids($request);
        $available = $request->validate(['is_available' => ['required', 'boolean']])['is_available'];

        $count = $restaurant->products()->whereIn('id', $ids)->update(['is_available' => $available]);
        // A bulk update fires no model event; the public menu must not wait out its TTL.
        PublicCache::bump('catalog');

        return $count;
    }

    public static function orderCategories(Restaurant $restaurant, Request $request): void
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        OptionGroupWriter::reorder($restaurant->menuCategories()->get(), $request->input('ids'));
        PublicCache::bump('catalog');
    }

    public static function orderProducts(MenuCategory $category, Request $request): void
    {
        $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']]);
        OptionGroupWriter::reorder($category->products()->get(), $request->input('ids'));
        PublicCache::bump('catalog');
    }

    /**
     * `ids`, or `product_ids` — the name the restaurant dashboard sent, which the API
     * refused with 422 on every bulk toggle until doc 77.
     *
     * @return list<int>
     */
    private static function ids(Request $request): array
    {
        if (! $request->has('ids') && $request->has('product_ids')) {
            $request->merge(['ids' => $request->input('product_ids')]);
        }

        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        return collect($request->input('ids'))->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
