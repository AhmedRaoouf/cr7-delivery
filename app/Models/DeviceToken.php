<?php

namespace App\Models;

use App\Enums\DeviceApp;
use App\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'app', 'device_id', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'app' => DeviceApp::class,
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
