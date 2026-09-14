<?php

namespace App\Models;

use App\Enums\DeviceApp;
use App\Enums\DevicePlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'app', 'capabilities', 'app_version', 'device_id', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'app' => DeviceApp::class,
            'capabilities' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the place's ring (D-100) reaches a person on this device.
     *
     * An Android ring is data-only and drawn by the app, so only a build that says it can
     * draw it gets one — a phone on the pre-ring APK says nothing and gets the ordinary
     * push instead. An iOS ring carries its own alert, drawn by the system whatever the
     * build, and a browser never rings: the web board keeps its own cadence.
     */
    public function rings(): bool
    {
        return match ($this->platform?->value) {
            'ios' => true,
            'android' => in_array('ring', $this->capabilities ?? [], true),
            default => false,
        };
    }
}
