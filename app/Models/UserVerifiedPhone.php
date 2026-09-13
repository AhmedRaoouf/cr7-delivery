<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D-112 — a number an account has proven, by SMS, that it can be reached on.
 *
 * Written by `POST /addresses/phone/verify` and read when an address is saved with a
 * contact number that is not the account's own. It is a permission to *use* the number
 * on this account's addresses — never a sign-in, never a second phone on the account.
 * Once verified, it is the account's to reuse on any address.
 */
class UserVerifiedPhone extends Model
{
    protected $fillable = ['user_id', 'phone', 'verified_at'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
