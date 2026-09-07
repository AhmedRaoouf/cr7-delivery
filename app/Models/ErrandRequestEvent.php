<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an errand's history — what changed, who changed it, and why. */
class ErrandRequestEvent extends Model
{
    protected $fillable = ['errand_request_id', 'status', 'actor_role', 'actor_id', 'note'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ErrandRequest::class, 'errand_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
