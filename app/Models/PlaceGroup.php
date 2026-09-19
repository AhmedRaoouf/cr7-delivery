<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A hand-made segment of places — «مطاعم الشاطئ», «شركاء المميزين» — a media rule can name. */
class PlaceGroup extends Model
{
    protected $fillable = ['name_ar', 'name_en', 'description'];

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'place_group_restaurant');
    }
}
