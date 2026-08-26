<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaticPage extends Model
{
    protected $fillable = ['slug', 'title_ar', 'title_en', 'body_ar', 'body_en', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
