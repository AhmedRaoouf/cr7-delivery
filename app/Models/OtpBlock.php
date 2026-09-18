<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Batch 68 — a number the platform will not send a code to, or check one for, until
 * `blocked_until` — unless an operator lifts it first (`lifted_at`).
 *
 * @property string $phone
 * @property string $reason daily_cap|burned_codes|admin|abuse
 * @property Carbon $blocked_until
 * @property Carbon|null $lifted_at
 */
class OtpBlock extends Model
{
    public const REASON_DAILY_CAP = 'daily_cap';

    public const REASON_BURNED_CODES = 'burned_codes';

    public const REASON_ADMIN = 'admin';

    public const REASON_ABUSE = 'abuse';

    public const REASONS = [self::REASON_DAILY_CAP, self::REASON_BURNED_CODES, self::REASON_ADMIN, self::REASON_ABUSE];

    protected $fillable = [
        'phone', 'reason', 'blocked_until', 'note', 'created_by', 'lifted_at', 'lifted_by',
    ];

    protected function casts(): array
    {
        return [
            'blocked_until' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /** In force now: not lifted, and not yet run out. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at')->where('blocked_until', '>', now());
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null && $this->blocked_until->isFuture();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, mixed> the row as `GET /admin/otp/blocks` prints it */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'reason' => $this->reason,
            'blocked_until' => $this->blocked_until->toIso8601String(),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->creator ? ['id' => $this->creator->id, 'name' => $this->creator->name] : null,
            'lifted_at' => $this->lifted_at?->toIso8601String(),
        ];
    }
}
