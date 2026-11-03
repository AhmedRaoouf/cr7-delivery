<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * doc 99 §2 — D-170. One unbroken stretch of «online and heard from».
 *
 * Open while `ended_at` is null; at most one open row per captain (a unique index on the
 * generated `open_driver_id`). Written only by {@see \App\Support\DriverPresence}.
 */
class DriverPresenceSession extends Model
{
    public const WENT_OFFLINE = 'went_offline';

    public const SIGNAL_LOST = 'signal_lost';

    public const LOGGED_OUT = 'logged_out';

    public const ADMIN = 'admin';

    public const BLOCKED = 'blocked';

    protected $fillable = [
        'driver_id', 'started_at', 'last_seen_at', 'ended_at', 'end_reason',
        'start_lat', 'start_lng', 'start_in_coverage', 'start_zone_id',
    ];

    protected $hidden = ['open_driver_id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
            'start_lat' => 'decimal:7',
            'start_lng' => 'decimal:7',
            'start_in_coverage' => 'boolean',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }
}
