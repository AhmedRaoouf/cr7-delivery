<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cuisine extends Model
{
    protected $fillable = ['name_ar', 'name_en', 'icon', 'image', 'sort_order', 'is_active', 'is_fallback', 'restaurant_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_fallback' => 'boolean'];
    }

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'cuisine_restaurant');
    }

    /** Doc 82 — the dishes filed under this cuisine. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** The place that created this cuisine from its product form, if one did. */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * What the customer is offered as a filter: active, not «أخرى», and holding at
     * least one dish a customer can order right now — a cuisine with nothing behind it
     * is a chip that opens an empty list.
     */
    public function scopeOffered(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('is_fallback', false)
            ->whereHas('products', fn (Builder $q) => $q->orderableHere());
    }

    public static function fallback(): self
    {
        return static::query()->where('is_fallback', true)->firstOrFail();
    }
}
