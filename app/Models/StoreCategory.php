<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What kind of shop this is — «مطاعم», «صيدليات», «كافيهات», «سوبرماركت», «خضروات».
 *
 * Not a cuisine. A cuisine answers «what does this kitchen cook», and a pharmacy does
 * not cook. The five that ship are a seed, not a constant: the operator adds and removes
 * rows here and the app's home screen follows, which is the whole reason this is a table
 * and not an enum.
 */
class StoreCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug', 'name_ar', 'name_en', 'image', 'icon', 'color', 'sort_order', 'is_active', 'product_layout',
    ];

    /** doc 99 §8 — D-176: how a place in this category lays out its products. */
    public const LAYOUTS = ['list', 'grid'];

    /** The shops that sell goods rather than dishes start on the two-column grid. */
    public const GRID_SLUGS = ['pharmacies', 'supermarkets', 'greengrocers', 'gifts-surprises', 'cr-offers'];

    protected static function booted(): void
    {
        // The same default the migration wrote, for a category created after it (a fresh
        // seed, or an operator re-creating «صيدليات»). An explicit choice always wins.
        static::creating(function (StoreCategory $category): void {
            if ($category->getAttribute('product_layout') === null) {
                $category->product_layout = in_array($category->slug, self::GRID_SLUGS, true) ? 'grid' : 'list';
            }
        });
    }

    /** `list` unless the category says `grid` — a null or unknown value never breaks a payload. */
    public static function layoutOf(?StoreCategory $category): string
    {
        return $category?->product_layout === 'grid' ? 'grid' : 'list';
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }
}
