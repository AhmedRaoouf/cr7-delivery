<?php

namespace App\Models;

use App\Support\Auth\SessionIssuer;
use App\Support\Auth\Sessions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * D-120 — one refresh token, and the access token issued beside it.
 *
 * Written only by {@see SessionIssuer} and revoked only through
 * {@see Sessions}. Nothing reads `token_hash` or `grace_payload` outside those two, which
 * is why both are hidden: a row that reaches a `toArray()` by accident must not carry
 * what it takes to renew somebody's session.
 */
class RefreshToken extends Model
{
    protected $fillable = [
        'user_id', 'family', 'token_hash', 'access_token_id', 'client', 'device_id', 'ip',
        'user_agent', 'expires_at', 'rotated_at', 'replaced_by_id', 'revoked_at',
        'grace_payload', 'last_used_at', 'access_used_at',
    ];

    protected $hidden = ['token_hash', 'grace_payload'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'access_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'access_token_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    /**
     * Still inside its own window — both of them.
     *
     * `expires_at` is the window as it was when the row was written. The second clause
     * is the window as the operator has it **now**, counted from the same moment, so a
     * shortened `auth.admin_session_days` applies to every session the next time it is
     * presented rather than only to those issued after the edit. A lengthened one takes
     * effect at the next renewal, which writes the new `expires_at`.
     */
    public function isWithinWindow(User $owner): bool
    {
        return $this->expires_at->isFuture()
            && $this->created_at->copy()->addDays(Sessions::idleDays($owner))->isFuture();
    }

    /**
     * Whether either half of this pair has ever been used: the refresh token renewed, or
     * the access token authenticated a request.
     *
     * This is the line between a response that was lost on the way to the client — its
     * successor is untouched, so the client simply never had it — and a token presented a
     * second time by somebody who is not the client, which is what a used successor means.
     */
    public function wasUsed(): bool
    {
        if ($this->rotated_at !== null || $this->access_used_at !== null) {
            return true;
        }

        // `access_used_at` is the copy that outlives the access token's row; the row's own
        // `last_used_at` is read too while it exists, so the answer never rests on the
        // copy alone.
        return $this->access_token_id !== null
            && PersonalAccessToken::query()->whereKey($this->access_token_id)->whereNotNull('last_used_at')->exists();
    }
}
