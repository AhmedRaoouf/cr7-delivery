<?php

namespace App\Support\Catalog;

use App\Models\Cuisine;
use App\Models\Product;
use App\Models\Restaurant;
use App\Support\ArabicSearch;
use Illuminate\Support\Facades\DB;

/**
 * Doc 82 — creating a cuisine from a product form, and deleting one, the same way on
 * every surface (the place's dashboard, the admin's, and the app's two consoles).
 */
final class CuisineWriter
{
    /**
     * The cuisine with this Arabic name, created when none exists yet.
     *
     * Two places typing «مكرونة» and «مكرونه» mean one filter, so the name is compared
     * folded; the second writer gets the first one's row back and `$created` false.
     *
     * @param  array{name_ar: string, name_en?: string|null, image: string}  $data
     */
    public static function findOrCreate(array $data, ?Restaurant $by, ?bool &$created = null): Cuisine
    {
        $folded = ArabicSearch::normalize($data['name_ar']);

        $existing = Cuisine::query()
            ->get(['id', 'name_ar', 'name_en'])
            ->first(fn (Cuisine $c) => ArabicSearch::normalize($c->name_ar) === $folded
                || ($c->name_en !== null && ArabicSearch::normalize($c->name_en) === $folded));

        if ($existing !== null) {
            $created = false;

            return Cuisine::query()->findOrFail($existing->id);
        }

        $created = true;

        return Cuisine::query()->create([
            'name_ar' => trim($data['name_ar']),
            'name_en' => trim((string) ($data['name_en'] ?? '')) ?: trim($data['name_ar']),
            'image' => $data['image'],
            'sort_order' => ((int) Cuisine::query()->where('is_fallback', false)->max('sort_order')) + 1,
            'is_active' => true,
            'restaurant_id' => $by?->id,
        ]);
    }

    /**
     * Delete a cuisine; its dishes move to «أخرى» rather than to no cuisine at all.
     * The fallback itself cannot be deleted.
     */
    public static function delete(Cuisine $cuisine): void
    {
        abort_if($cuisine->is_fallback, 409, 'cuisine_is_fallback');

        DB::transaction(function () use ($cuisine): void {
            Product::withTrashed()
                ->where('cuisine_id', $cuisine->id)
                ->update(['cuisine_id' => Cuisine::fallback()->id]);

            $cuisine->delete();
        });

        CuisineClassifier::flush();
    }
}
