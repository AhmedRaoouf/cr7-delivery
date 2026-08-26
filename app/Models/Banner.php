<?php

namespace App\Models;

use App\Enums\BannerLinkType;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = [
        'title_ar', 'title_en', 'image_ar', 'image_en', 'link_type', 'link_value',
        'city_ids', 'sort_order', 'starts_at', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'link_type' => BannerLinkType::class,
            'city_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
