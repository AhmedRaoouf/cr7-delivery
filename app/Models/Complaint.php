<?php

namespace App\Models;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintPriority;
use App\Enums\ComplaintResolutionOutcome;
use App\Enums\ComplaintStatus;
use App\Enums\ComplaintUserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Complaint extends Model
{
    protected $fillable = [
        'ticket_number', 'user_id', 'user_role', 'order_id', 'order_group_id',
        'restaurant_id', 'category', 'subject', 'description', 'attachments',
        'status', 'priority', 'assigned_to', 'refund_id', 'visible_to_restaurant',
        'restaurant_first_read_at', 'restaurant_replied_at', 'first_response_at',
        'due_at', 'resolution_outcome', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'user_role' => ComplaintUserRole::class,
            'category' => ComplaintCategory::class,
            'status' => ComplaintStatus::class,
            'priority' => ComplaintPriority::class,
            'resolution_outcome' => ComplaintResolutionOutcome::class,
            'attachments' => 'array',
            'visible_to_restaurant' => 'boolean',
            'restaurant_first_read_at' => 'datetime',
            'restaurant_replied_at' => 'datetime',
            'first_response_at' => 'datetime',
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderGroup(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class);
    }

    /** Doc 91 — a complaint outlives the place it is about. */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class)->withTrashed();
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ComplaintMessage::class);
    }

    /**
     * Open and waiting on staff: the last visible message is the complainant's. A reply
     * from the office or the place takes it off the alert dock; the customer writing
     * again puts it back. Status is untouched — closing is a separate decision.
     */
    public function scopeAwaitingReply($query)
    {
        return $query->whereIn('complaints.status', ['open', 'in_progress'])
            ->whereRaw(
                "coalesce((select m.sender_role from complaint_messages m where m.complaint_id = complaints.id and m.visibility = 'all' order by m.id desc limit 1), 'customer') in ('customer', 'driver')"
            );
    }
}
