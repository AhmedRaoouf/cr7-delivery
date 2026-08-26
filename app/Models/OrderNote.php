<?php

namespace App\Models;

use App\Enums\OrderNoteCallOutcome;
use App\Enums\OrderNoteCalledParty;
use App\Enums\OrderNoteKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderNote extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'order_id', 'order_group_id', 'admin_id', 'kind', 'called_party',
        'call_outcome', 'body', 'attachments',
    ];

    protected function casts(): array
    {
        return [
            'kind' => OrderNoteKind::class,
            'called_party' => OrderNoteCalledParty::class,
            'call_outcome' => OrderNoteCallOutcome::class,
            'attachments' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderGroup(): BelongsTo
    {
        return $this->belongsTo(OrderGroup::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
