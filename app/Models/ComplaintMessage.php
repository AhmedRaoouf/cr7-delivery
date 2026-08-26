<?php

namespace App\Models;

use App\Enums\ComplaintMessageSenderRole;
use App\Enums\ComplaintMessageVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintMessage extends Model
{
    protected $fillable = ['complaint_id', 'sender_id', 'sender_role', 'visibility', 'message', 'attachments'];

    protected function casts(): array
    {
        return [
            'sender_role' => ComplaintMessageSenderRole::class,
            'visibility' => ComplaintMessageVisibility::class,
            'attachments' => 'array',
        ];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
