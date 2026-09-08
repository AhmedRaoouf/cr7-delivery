<?php

namespace App\Models;

use App\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider identity, after it has been attached to a real CR7 account.
 *
 * A row here is what turns the second «المتابعة باستخدام Apple» into a sign-in with no
 * code to type: the subject comes back the same every time, so the account is found and
 * the phone step is never asked for again.
 */
class SocialAccount extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'provider_user_id', 'email', 'name', 'email_verified', 'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'email_verified' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
