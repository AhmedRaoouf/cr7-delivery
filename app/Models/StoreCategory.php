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
        'slug', 'name_ar', 'name_en', 'image', 'icon', 'color', 'sort_order', 'is_active',
    ];

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
