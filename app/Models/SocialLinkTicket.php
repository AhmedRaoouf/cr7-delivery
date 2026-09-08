<?php

namespace App\Models;

use App\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * A verified provider identity that has no account yet, waiting for a phone number.
 *
 * Same discipline as `otp_codes.follow_up_token`: it carries one fact between two
 * requests, it dies on its own clock, and it is spent exactly once. It holds the name
 * Apple sent, because Apple sends it on the first authorisation and never again — asking
 * the provider a second time returns a token with no name on it at all.
 */
class SocialLinkTicket extends Model
{
    protected $fillable = [
        'provider', 'provider_user_id', 'email', 'name', 'email_verified',
        'token', 'expires_at', 'consumed_at',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'email_verified' => 'boolean',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
