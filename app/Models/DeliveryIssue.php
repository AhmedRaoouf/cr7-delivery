<?php

namespace App\Models;

use App\Enums\DeliveryIssueCategory;
use App\Enums\DeliveryIssueReportedBy;
use App\Enums\DeliveryIssueStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryIssue extends Model
{
    protected $fillable = [
        'order_id', 'reported_by', 'reporter_id', 'category', 'description',
        'attachments', 'status', 'resolved_by', 'resolution_note', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'reported_by' => DeliveryIssueReportedBy::class,
            'category' => DeliveryIssueCategory::class,
            'status' => DeliveryIssueStatus::class,
            'attachments' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
