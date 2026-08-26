<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'restaurant_id', 'menu_category_id', 'name_ar', 'name_en',
        'description_ar', 'description_en', 'image', 'gallery', 'price',
        'discount_price', 'prep_minutes', 'calories', 'ingredients_ar',
        'ingredients_en', 'allergens', 'spice_level', 'serving_size_ar',
        'serving_size_en', 'tags', 'is_available', 'is_featured', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'gallery' => 'array',
            'price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'allergens' => 'array',
            'rating_avg' => 'decimal:2',
            'tags' => 'array',
            'is_available' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function menuCategory(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class);
    }
}
