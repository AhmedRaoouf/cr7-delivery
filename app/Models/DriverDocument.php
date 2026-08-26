<?php

namespace App\Models;

use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverDocument extends Model
{
    protected $fillable = ['driver_id', 'type', 'file', 'expires_at', 'status', 'note'];

    protected function casts(): array
    {
        return [
            'type' => DriverDocumentType::class,
            'status' => DriverDocumentStatus::class,
            'expires_at' => 'date',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
