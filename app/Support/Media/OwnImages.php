<?php

namespace App\Support\Media;

use App\Enums\MediaUsage;
use App\Models\Restaurant;
use Illuminate\Support\Collection;

/**
 * The pictures a place already has (doc 71 §5) — derived, never copied.
 *
 * «Existing images already assigned to a restaurant/product/category must keep showing
 * normally for that owner automatically, even if Admin has not added them to the shared
 * library.» So nothing is imported: the picker reads these three tables at request time,
 * and a picture uploaded a year ago is offered the same day the picker ships.
 *
 * Each URL appears once, labelled by the first thing that holds it. The usage is the
 * kind of field it lives in — a logo is offered for a logo field, a dish photo for a
 * dish — so «only images valid for the current field type» holds for these as it does
 * for the library.
 */
final class OwnImages
{
    /**
     * @return Collection<int, array{ref: string, url: string, name: string, usage: string, used_by: array{type: string, id: int}}>
     */
    public static function of(Restaurant $restaurant): Collection
    {
        $found = [];

        $add = function (?string $url, MediaUsage $usage, string $name, string $type, int $id) use (&$found): void {
            if (! is_string($url) || $url === '') {
                return;
            }

            $key = $usage->value.'|'.$url;
            $found[$key] ??= [
                'ref' => self::ref($url),
                'url' => $url,
                'name' => $name,
                'usage' => $usage->value,
                'used_by' => ['type' => $type, 'id' => $id],
            ];
        };

        $add($restaurant->logo, MediaUsage::Logo, $restaurant->name_ar, 'restaurant', $restaurant->id);
        $add($restaurant->cover, MediaUsage::Cover, $restaurant->name_ar, 'restaurant', $restaurant->id);

        $restaurant->products()->select(['id', 'name_ar', 'image', 'gallery'])
            ->where(fn ($q) => $q->whereNotNull('image')->orWhereNotNull('gallery'))
            ->orderBy('id')
            ->get()
            ->each(function ($product) use ($add): void {
                $add($product->image, MediaUsage::Product, $product->name_ar, 'product', $product->id);

                foreach ((array) $product->gallery as $url) {
                    $add(is_string($url) ? $url : null, MediaUsage::Product, $product->name_ar, 'product', $product->id);
                }
            });

        $restaurant->menuCategories()->select(['id', 'name_ar', 'image'])
            ->whereNotNull('image')
            ->orderBy('id')
            ->get()
            ->each(fn ($c) => $add($c->image, MediaUsage::Category, $c->name_ar, 'category', $c->id));

        return collect(array_values($found));
    }

    /** The opaque handle a client sends back to pick this picture. */
    public static function ref(string $url): string
    {
        return 'own:'.sha1($url);
    }
}
