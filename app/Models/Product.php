<?php

namespace App\Models;

use App\Support\Media\ProductGallery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    /** How many pictures one product's gallery may hold — the single place the cap lives. */
    public const GALLERY_MAX = 12;

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

    /**
     * Batch 72 — the per-picture view of `gallery`: `[{ref, url, thumb_url, position}]`.
     *
     * Appended **per instance** ({@see self::withGalleryMeta()}) and only on the
     * restaurant and admin management payloads; the customer API never carries it.
     *
     * @return list<array{ref: string, url: string, thumb_url: string, position: int}>
     */
    public function getGalleryItemsAttribute(): array
    {
        return ProductGallery::items($this);
    }

    /** The optimistic-lock token of `gallery` — changes whenever the list or its order does. */
    public function getGalleryVersionAttribute(): string
    {
        return ProductGallery::version(ProductGallery::urls($this));
    }

    /** Publish `gallery_items` and `gallery_version` on this instance's JSON. */
    public function withGalleryMeta(): static
    {
        return $this->append(['gallery_items', 'gallery_version']);
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
        // D-164 — a group with no options is a draft: never shown, never enforced.
        if ($this->relationLoaded('optionGroups')) {
            return $this->optionGroups->contains(fn (ProductOptionGroup $group) => $group->is_required && $group->options->isNotEmpty());
        }

        return $this->optionGroups()
            ->where('is_required', true)
            ->whereHas('options')
            ->exists();
    }

    /** D-166 — at least one group the customer will see. */
    public function hasOptions(): bool
    {
        if ($this->relationLoaded('optionGroups')) {
            return $this->optionGroups->contains(fn (ProductOptionGroup $group) => $group->options->isNotEmpty());
        }

        return $this->optionGroups()->whereHas('options')->exists();
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class);
    }
}
