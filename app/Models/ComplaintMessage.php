<?php

namespace App\Models;

use App\Enums\ComplaintMessageSenderRole;
use App\Enums\ComplaintMessageVisibility;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintMessage extends Model
{
    protected $fillable = ['complaint_id', 'sender_id', 'sender_role', 'visibility', 'message', 'attachments'];

    /**
     * `body` and `sender_name` are wire aliases, not columns.
     *
     * Both dashboards render `message.body` and the column is `message`, so every bubble
     * on `ADM-41` and `RES-31` drew an empty box under a correct timestamp — the reply was
     * there, the reader could not see a word of it. Aliasing on the model fixes both
     * dashboards and leaves the mobile client, which reads `message`, untouched.
     */
    protected $appends = ['body', 'sender_name'];

    protected function casts(): array
    {
        return [
            'sender_role' => ComplaintMessageSenderRole::class,
            'visibility' => ComplaintMessageVisibility::class,
            'attachments' => 'array',
        ];
    }

    protected function body(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->message);
    }

    /** Null unless the sender was eager-loaded — an alias must never fire a query per row. */
    protected function senderName(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->relationLoaded('sender') ? $this->sender?->name : null);
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
