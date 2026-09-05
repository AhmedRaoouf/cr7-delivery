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

    /**
     * Whether «+» on a list row has to open the dish rather than add it.
     *
     * This is the same question `AddCartItemAction::validateOptions()` answers,
     * and it has to be answered the same way or the quick-add sends a basket
     * the cart refuses: that action rejects **any** required group with nothing
     * chosen in it, whatever the group offers. A single-option required group
     * is not auto-filled anywhere — it is simply a 422 nobody asked for — so it
     * counts here too.
     *
     * Existence rather than a load — this is called once per row on a search
     * that can return dishes from every restaurant, and `withCount` at the call
     * sites would be a second thing to remember.
     */
    public function requiresConfiguration(): bool
    {
        return $this->optionGroups()
            ->where('is_required', true)
            ->exists();
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class);
    }
}
