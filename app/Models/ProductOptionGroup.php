<?php

namespace App\Models;

use App\Enums\OptionPriceMode;
use App\Enums\OptionSelectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOptionGroup extends Model
{
    protected $fillable = [
        'product_id', 'name_ar', 'name_en', 'selection_type', 'price_mode',
        'is_required', 'min_select', 'max_select', 'sort_order',
    ];

    /** D-164 — every staff payload says whether the customer can see the group yet. */
    protected $appends = ['is_draft'];

    protected function casts(): array
    {
        return [
            'selection_type' => OptionSelectionType::class,
            // doc 78 — `final`: the chosen option's price is the dish's price.
            'price_mode' => OptionPriceMode::class,
            'is_required' => 'boolean',
        ];
    }

    /** A group with no options is a draft: not shown to the customer, never enforced. */
    public function getIsDraftAttribute(): bool
    {
        return $this->relationLoaded('options') ? $this->options->isEmpty() : ! $this->options()->exists();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }
}
