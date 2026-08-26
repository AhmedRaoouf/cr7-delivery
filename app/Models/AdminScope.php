<?php

namespace App\Models;

use App\Enums\AdminScopeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminScope extends Model
{
    protected $fillable = ['user_id', 'scope_type', 'city_id', 'zone_id'];

    protected function casts(): array
    {
        return [
            'scope_type' => AdminScopeType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class, 'zone_id');
    }
}
