<?php

namespace App\Models;

use App\Enums\CaptainApplicationStatus;
use App\Enums\VehicleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D-45 — `APP-03` submits an *application*, never an account. A captain account is
 * created by an admin, out of an approved row here, through CreateDriverAction.
 */
class CaptainApplication extends Model
{
    protected $fillable = [
        'reference', 'full_name', 'phone', 'email', 'national_id', 'city_id',
        'city_text', 'vehicle_type', 'vehicle_plate', 'licence_file',
        'national_id_file', 'national_id_back_file', 'note', 'status', 'rejection_reason', 'reviewed_by',
        'reviewed_at', 'created_driver_id',
    ];

    /**
     * The licence and the two ID sides are private-disk paths — never on a public
     * response, and never on an admin one either. An admin reads them through
     * `GET /admin/captain-applications/{id}/files/{kind}`, which checks the scope
     * first; a path in a payload is a file anyone who can read the payload can guess
     * their way to.
     */
    protected $hidden = ['licence_file', 'national_id_file', 'national_id_back_file'];

    protected function casts(): array
    {
        return [
            'status' => CaptainApplicationStatus::class,
            'vehicle_type' => VehicleType::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'created_driver_id');
    }

    /**
     * Figma `894:5150` — `CAP-260823-0147`: the prefix, the date the application was
     * filed, and its number that day. Generated inside the same transaction that
     * inserts the row, so two applications a second apart cannot collide.
     */
    public static function nextReference(): string
    {
        $day = now();
        $sequence = static::query()->whereDate('created_at', $day->toDateString())->count() + 1;

        return sprintf('CAP-%s-%04d', $day->format('ymd'), $sequence);
    }
}
