<?php

namespace App\Models;

use App\Enums\OptionSelectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductOptionGroup extends Model
{
    protected $fillable = [
        'product_id', 'name_ar', 'name_en', 'selection_type',
        'is_required', 'min_select', 'max_select', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'selection_type' => OptionSelectionType::class,
            'is_required' => 'boolean',
        ];
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
