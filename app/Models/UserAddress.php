<?php

namespace App\Models;

use App\Enums\AddressLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserAddress extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'city_id', 'service_zone_id', 'label', 'title', 'street',
        'building', 'floor', 'apartment', 'landmark', 'contact_phone',
        'latitude', 'longitude', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'label' => AddressLabel::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_default' => 'boolean',
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

    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    /**
     * D-83 — the one sentence an order carries as its destination.
     *
     * Operator, 2026-09-11: «الداتا اللي في العنوان … يبقا هما اللي في الاوردر».
     * The old composer lived privately inside `CreateOrderAction`, glued five
     * optional parts together and dropped the address's own name, its area and
     * its city — so an address whose customer had filled only «بجوار المسجد»
     * reached the captain as those two words and nothing else.
     *
     * Every numeric part is labelled and isolated: «‪3‬» beside Arabic
     * punctuation is a number the reader cannot place and, for a floor beside a
     * flat number, one they can place wrongly.
     */
    public function composeText(): string
    {
        $parts = [
            $this->title,
            $this->street,
            filled($this->building) ? 'مبنى '.self::ltr($this->building) : null,
            filled($this->floor) ? 'الدور '.self::ltr($this->floor) : null,
            filled($this->apartment) ? 'شقة '.self::ltr($this->apartment) : null,
            $this->landmark,
            $this->serviceZone?->name_ar,
            $this->city?->name_ar,
        ];

        return collect($parts)
            ->map(fn (?string $part) => is_string($part) ? trim($part) : null)
            ->filter()
            ->unique()
            ->implode('، ');
    }

    /**
     * D-83 — who the captain calls.
     *
     * The address's own number when it has one, the account's otherwise. It was
     * `?? $user->phone`, which is a different rule: an address saved with an
     * empty string — what the form sent for a field the customer skipped —
     * stored that empty string on the order and left «اتصال» with nothing to
     * dial.
     */
    public function resolvedContactPhone(?User $user = null): ?string
    {
        if (filled($this->contact_phone)) {
            return $this->contact_phone;
        }

        return ($user ?? $this->user)?->phone;
    }

    /** D-81 — what delivery to this address costs before any offer is applied. */
    public function deliveryFee(): float
    {
        return (float) ($this->serviceZone?->delivery_fee ?? 0);
    }

    /** LEFT-TO-RIGHT EMBEDDING … POP DIRECTIONAL FORMATTING — doc 13 §4. */
    private static function ltr(string $value): string
    {
        return "\u{202A}".trim($value)."\u{202C}";
    }
}
