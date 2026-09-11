<?php

namespace App\Models;

use App\Enums\ErrandStatus;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** «اطلب كابتن» — a point-to-point delivery with no restaurant in it. See the migration. */
class ErrandRequest extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'city_id', 'service_zone_id', 'status',
        'pickup_lat', 'pickup_lng', 'pickup_address_text', 'pickup_contact_phone',
        'dropoff_lat', 'dropoff_lng', 'dropoff_address_text', 'dropoff_contact_phone',
        'details', 'distance_km', 'offered_fee', 'driver_earning', 'platform_margin', 'suggested_fee',
        'driver_id', 'assigned_by', 'assigned_by_user_id',
        'offered_driver_id', 'offered_by_user_id', 'offered_at', 'declined_driver_ids',
        'purchases_amount', 'purchases_note', 'receipt_urls',
        'cancelled_by', 'cancel_reason',
        'assigned_at', 'picked_up_at', 'delivered_at', 'cancelled_at', 'admin_notified_at',
        'notified_driver_ids', 'last_published_at',
    ];

    /**
     * `total_due` is the only number the customer is asked for at the door, so it is
     * derived rather than stored: a captain who edits the receipt amount must never be
     * able to leave a stale total behind on the row the customer is reading.
     */
    protected $appends = ['total_due', 'status_label'];

    protected function casts(): array
    {
        return [
            'status' => ErrandStatus::class,
            'pickup_lat' => 'decimal:7',
            'pickup_lng' => 'decimal:7',
            'dropoff_lat' => 'decimal:7',
            'dropoff_lng' => 'decimal:7',
            'distance_km' => 'decimal:3',
            'offered_fee' => 'decimal:2',
            'suggested_fee' => 'decimal:2',
            'purchases_amount' => 'decimal:2',
            'receipt_urls' => 'array',
            'notified_driver_ids' => 'array',
            'declined_driver_ids' => 'array',
            'offered_at' => 'datetime',
            'last_published_at' => 'datetime',
            'assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'admin_notified_at' => 'datetime',
        ];
    }

    protected function totalDue(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->offered_fee + (float) $this->purchases_amount, 2));
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn (): string => $this->status->label());
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * The captain an operator has handed this to and who has not answered yet.
     *
     * Kept apart from `driver_id` on purpose: an offer is not an assignment, and until
     * «اقبل» is pressed the errand has no captain. Reading the offer as ownership is
     * exactly the bug this column exists to prevent.
     */
    public function offeredDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'offered_driver_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function serviceZone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ErrandRequestEvent::class);
    }

    /** Straight-line pickup→dropoff, in km. The road is longer; this is what we price on. */
    public function measureDistanceKm(): float
    {
        return Geo::haversineKm(
            (float) $this->pickup_lat,
            (float) $this->pickup_lng,
            (float) $this->dropoff_lat,
            (float) $this->dropoff_lng,
        );
    }

    public function record(ErrandStatus $status, ?User $actor, ?string $role = null, ?string $note = null): void
    {
        $this->events()->create([
            'status' => $status->value,
            'actor_role' => $role,
            'actor_id' => $actor?->id,
            'note' => $note,
        ]);
    }
}
