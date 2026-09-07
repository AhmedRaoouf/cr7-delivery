<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $fillable = [
        'phone', 'code_hash', 'purpose', 'attempts', 'expires_at', 'consumed_at',
        'follow_up_token', 'follow_up_expires_at',
    ];

    protected $hidden = ['code_hash', 'follow_up_token'];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'follow_up_expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * The proof that this phone answered its own code, good for one registration.
     *
     * Registration is two requests — verify the code, then choose a password — because
     * the customer types the password *after* being told the code was right. This is
     * what carries the fact between them, and it dies on its own clock so a token
     * scraped off a screen an hour later is worth nothing.
     */
    public function followUpIsUsable(): bool
    {
        return $this->follow_up_token !== null
            && $this->follow_up_expires_at !== null
            && $this->follow_up_expires_at->isFuture();
    }
}
