<?php

namespace App\Support\Auth;

use App\Enums\UserType;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\SettingsCatalog;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * D-120 — how long a session lives, and every way one ends.
 *
 * Before this, eight places ended sessions by hand with `->tokens()->delete()`, and the
 * sign-out button deleted one token. That was complete only because a session *was* one
 * token. It is two now — an access token and the refresh token that renews it — and a
 * site that deletes the first and forgets the second has signed nobody out: the next
 * renewal brings the session straight back. So every one of those sites calls here, and
 * this is the only code that knows a session has two halves.
 *
 * | What happened | What ends |
 * |---|---|
 * | «تسجيل الخروج» | this sign-in: its access token and its whole family |
 * | «الخروج من كل الأجهزة», reset password, block, delete | everything the account holds |
 * | change password | everything except the sign-in that changed it |
 * | a refresh token presented twice | that family (see {@see SessionIssuer}) |
 */
final class Sessions
{
    /** How long a dead refresh row is kept, for the theft check, before it is deleted. */
    public const PRUNE_AFTER_DAYS = 7;

    public static function accessMinutes(): int
    {
        return max(1, (int) config('cr7_auth.access_minutes', 60));
    }

    /**
     * How long a session may sit unused before it is gone — the operator's number, from
     * `ADM-46`.
     *
     * An admin's is its own and shorter by default (‪14‬ days against ‪60‬): an admin token
     * can refund an order, move a captain's balance and read every customer's phone
     * number, and nobody administers the platform from a phone left in a drawer for a
     * month.
     */
    public static function idleDays(User|UserType $who): int
    {
        $type = $who instanceof User ? $who->type : $who;

        return $type === UserType::Admin
            ? SettingsCatalog::int('auth.admin_session_days')
            : SettingsCatalog::int('auth.session_days');
    }

    /**
     * Sanctum's own validity check, extended to the legacy token.
     *
     * Registered with `Sanctum::authenticateAccessTokensUsing()`, which hands over the
     * token **and Sanctum's own verdict** — `expires_at` already judged — so this can only
     * narrow it, never widen it. A token with an `expires_at` is an access token issued
     * beside a refresh token and Sanctum's answer is the whole answer.
     *
     * A token without one is what every sign-in issued before D-120, and what the
     * 1.0.3/1.0.4 apps still receive. It used to live for ever. It now lives while it is
     * used: `last_used_at` (or `created_at`, if it never was) must fall inside the same
     * idle window a refresh token gets. A place that opens its board every day never
     * notices; a token copied off a phone that has been in a drawer for two months stops
     * working on its own.
     */
    public static function accepts(PersonalAccessToken $token, bool $isValid): bool
    {
        if (! $isValid || $token->expires_at !== null) {
            return $isValid;
        }

        $owner = $token->tokenable;

        if (! $owner instanceof User) {
            return $isValid;
        }

        $lastSeen = $token->last_used_at ?? $token->created_at;

        return $lastSeen !== null && $lastSeen->gt(now()->subDays(self::idleDays($owner)));
    }

    /** The sign-in an access token belongs to, or null for a legacy token that has none. */
    public static function familyOf(PersonalAccessToken $token): ?string
    {
        return RefreshToken::query()->where('access_token_id', $token->getKey())->value('family');
    }

    /**
     * «تسجيل الخروج» — this sign-in and nothing else.
     *
     * A legacy token has no family and is simply deleted, which is exactly what signing
     * out always did.
     */
    public static function endCurrent(User $user): void
    {
        $token = self::currentToken($user);

        if ($token === null) {
            return;
        }

        $family = self::familyOf($token);

        $family === null ? $token->delete() : self::revokeFamily($family);
    }

    /** Every session the account holds, refresh tokens included. */
    public static function revokeAll(User $user): void
    {
        self::retireRefreshTokens(RefreshToken::query()->where('user_id', $user->getKey()));

        $user->tokens()->delete();
    }

    /**
     * Every session except the one making this request — what a password change leaves.
     *
     * The sign-in that typed the new password is the one person known to hold it; every
     * other device held the old one, and that is the credential being replaced.
     */
    public static function revokeOthers(User $user): void
    {
        $keep = self::currentToken($user);
        $family = $keep === null ? null : self::familyOf($keep);

        self::retireRefreshTokens(
            RefreshToken::query()
                ->where('user_id', $user->getKey())
                ->when($family !== null, fn ($query) => $query->where('family', '!=', $family)),
        );

        $user->tokens()
            ->when($keep !== null, fn ($query) => $query->whereKeyNot($keep->getKey()))
            ->delete();
    }

    /** One sign-in: every refresh token descended from it and every access token they hold. */
    public static function revokeFamily(string $family): void
    {
        $accessTokenIds = RefreshToken::query()
            ->where('family', $family)
            ->whereNotNull('access_token_id')
            ->pluck('access_token_id');

        self::retireRefreshTokens(RefreshToken::query()->where('family', $family));

        if ($accessTokenIds->isNotEmpty()) {
            PersonalAccessToken::query()->whereKey($accessTokenIds->all())->delete();
        }
    }

    /**
     * What `cr7:prune-sessions` removes.
     *
     * - A successor pair held for the grace window, once the window is over. It is a live
     *   pair in the only form this table ever holds one that can be read back.
     * - A refresh row that has been dead — expired, revoked or rotated — for a week. The
     *   week is the theft check's memory: a rotated token presented inside it still revokes
     *   its family; after it, it is merely unknown.
     * - A legacy access token past its idle window by a week. It can no longer
     *   authenticate anything ({@see accepts()}), and a dead credential is still a
     *   credential in a dump.
     *
     * @return array{scrubbed: int, refresh_tokens: int, legacy_tokens: int}
     */
    public static function prune(): array
    {
        $graceOver = now()->subSeconds(SessionIssuer::GRACE_SECONDS);
        $deadSince = now()->subDays(self::PRUNE_AFTER_DAYS);

        $scrubbed = RefreshToken::query()
            ->whereNotNull('grace_payload')
            ->where('rotated_at', '<', $graceOver)
            ->update(['grace_payload' => null]);

        // Three deletes rather than one `OR`, so each walks its own index — the table gains a
        // row per session per hour of use.
        $refreshTokens = 0;

        foreach (['expires_at', 'revoked_at', 'rotated_at'] as $diedAt) {
            $refreshTokens += RefreshToken::query()->where($diedAt, '<', $deadSince)->delete();
        }

        $legacyTokens = 0;

        // Two windows, so two sweeps: an admin's and everybody else's.
        foreach ([true, false] as $admins) {
            $idleSince = now()->subDays(
                self::idleDays($admins ? UserType::Admin : UserType::Customer) + self::PRUNE_AFTER_DAYS,
            );

            $owners = User::withTrashed()->select('id')->where(
                'type',
                $admins ? '=' : '!=',
                UserType::Admin->value,
            );

            $legacyTokens += PersonalAccessToken::query()
                ->whereNull('expires_at')
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $owners)
                ->whereRaw('COALESCE(last_used_at, created_at) < ?', [$idleSince])
                ->delete();
        }

        return ['scrubbed' => $scrubbed, 'refresh_tokens' => $refreshTokens, 'legacy_tokens' => $legacyTokens];
    }

    /**
     * The token this request authenticated with, when it is a real row.
     *
     * `Sanctum::actingAs` hands a test a mock that was never saved, and a cookie session
     * would hand a `TransientToken`; neither is a session this class can end.
     */
    private static function currentToken(User $user): ?PersonalAccessToken
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken && $token->exists ? $token : null;
    }

    /** Revoked, and with nothing left in them that could be read back as a session. */
    private static function retireRefreshTokens(Builder $query): void
    {
        (clone $query)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        (clone $query)->whereNotNull('grace_payload')->update(['grace_payload' => null]);
    }
}
