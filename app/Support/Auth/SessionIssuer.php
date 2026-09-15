<?php

namespace App\Support\Auth;

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * D-120 — the only code that issues a session.
 *
 * Three sign-ins used to call `createToken` each (`PasswordLoginAction`,
 * `VerifyOtpAction`, `RegisterCustomerAction`), and a token that never expired was the
 * whole of what they issued. They all come here now, and so do the two ways a session
 * continues without a sign-in: `refresh()` and `upgrade()`.
 *
 * A session is an **access token** — a Sanctum token with an `expires_at` an hour out —
 * and a **refresh token** that buys the next one. The refresh token is opaque, stored as a
 * sha256, and single-use: presenting it rotates it, and the rotated row remembers what it
 * was rotated into. That memory is what makes the three hard cases decidable:
 *
 * - **The same token twice within ‪120‬ s** is a client racing itself — a board firing
 *   five requests that all found the access token expired, or the app's main and
 *   background isolates. Every one of them gets the pair the first one got.
 * - **The same token again later, with its successor never used**, is a response that
 *   never reached the client. The unused successor is withdrawn and a fresh pair issued.
 * - **The same token again later, with its successor used**, means two parties hold this
 *   sign-in and one of them is not the client. There is no telling which, so the whole
 *   sign-in ends and whoever is legitimate signs in again.
 *
 * Every refusal is the same 401 `session_expired` in the same words — unknown, expired,
 * revoked and reused alike. A caller probing with refresh tokens learns nothing from the
 * answer about which one it found.
 */
final class SessionIssuer
{
    /** How long a rotated refresh token keeps answering with the pair it was rotated into. */
    public const GRACE_SECONDS = 120;

    /** Characters in a refresh token: letters and digits, so it is URL- and header-safe. */
    public const REFRESH_TOKEN_LENGTH = 80;

    /**
     * What a sign-in answers with, to be merged into its `data`.
     *
     * Without the capability header, `{token}` and nothing else — a legacy token with no
     * `expires_at`, byte for byte what this endpoint returned before D-120. With it, the
     * whole session block.
     *
     * @return array<string, mixed>
     */
    public function signIn(User $user, SessionClient $client): array
    {
        if (! $client->refreshable) {
            return ['token' => $user->createToken($user->type->value, [$user->type->value])->plainTextToken];
        }

        return DB::transaction(fn (): array => $this->mint($user, $client)['session']);
    }

    /**
     * `POST /auth/refresh`.
     *
     * Decided and written under a row lock on the presented token, so two presentations of
     * it are serialised: the second one waits, then finds the row already rotated and
     * takes the replay path instead of rotating it a second time. The outcome is returned
     * out of the transaction and thrown after it commits — a family revoked on the way to a
     * refusal must stay revoked.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 401 `session_expired` · 423 `account_suspended`
     */
    public function refresh(string $plainRefreshToken, SessionClient $client): array
    {
        [$outcome, $payload] = DB::transaction(function () use ($plainRefreshToken, $client): array {
            $row = RefreshToken::query()
                ->where('token_hash', hash('sha256', $plainRefreshToken))
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ['expired', null];
            }

            // Soft-deleted accounts are not found, and a deleted account has no session.
            $owner = User::query()->find($row->user_id);

            if ($owner === null) {
                Sessions::revokeFamily($row->family);

                return ['expired', null];
            }

            // Before the revoked check on purpose. Blocking an account revokes every
            // token it holds, so a blocked account's refresh token is always revoked — and
            // «انتهت الجلسة» would tell the person nothing about why. Whoever presents a
            // token that was this account's already held this account.
            if ($owner->isBlocked()) {
                Sessions::revokeAll($owner);

                return ['suspended', $owner];
            }

            if ($row->revoked_at !== null) {
                // Idempotent for every family that was revoked whole — a sign-out, a
                // password change, a theft already caught. The one row revoked on its own
                // is the successor a lost-response recovery withdrew (below): nobody but a
                // second holder can have it, so its return ends the family too.
                Sessions::revokeFamily($row->family);

                return ['expired', null];
            }

            if (! $row->isWithinWindow($owner)) {
                return ['expired', null];
            }

            if ($row->rotated_at === null) {
                return ['ok', $this->rotate($row, $owner, $client)];
            }

            if (($replay = $this->replay($row)) !== null) {
                return ['ok', $replay];
            }

            $successor = $row->replaced_by_id === null
                ? null
                : RefreshToken::query()->lockForUpdate()->find($row->replaced_by_id);

            if ($successor !== null && $successor->revoked_at === null && ! $successor->wasUsed()) {
                // The response carrying the successor never arrived: nothing has used it,
                // so the client never had it. Withdraw it, and answer this time.
                $this->withdraw($successor);

                return ['ok', $this->rotate($row, $owner, $client)];
            }

            Log::warning('[session] a rotated refresh token was presented again after its successor was used — the sign-in is revoked', [
                'user_id' => $owner->id,
                'family' => $row->family,
                'client' => $client->app,
                'ip' => $client->ip,
            ]);

            Sessions::revokeFamily($row->family);

            return ['expired', null];
        });

        return match ($outcome) {
            'ok' => $payload,
            'suspended' => throw new ApiException('account_suspended', EnsureAccountIsActive::sentence($payload), 423),
            default => throw self::expired(),
        };
    }

    /**
     * `POST /auth/session/upgrade` — a legacy token, exchanged for a session that renews.
     *
     * How an app updated from 1.0.4, or a dashboard cookie from before this deploy, joins
     * the new model without making anybody sign in again.
     *
     * The legacy token is not deleted at once: it is given ‪120‬ s to live and the pair is
     * held for as long, so an upgrade that several requests start together — a dashboard
     * page whose server components each find the old token, both isolates of the app — is
     * answered with **one** pair, and a request already on the wire with the old token
     * still lands. Deleting it on the first upgrade would answer every one of the others
     * with a 401, which a client can only read as «انتهت الجلسة». After the ‪120‬ s it is
     * refused by Sanctum's own `expires_at` check and pruned with every other expired token.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException 409 `session_already_refreshable` · 401 `session_expired`
     */
    public function upgrade(User $user, PersonalAccessToken $bearer, SessionClient $client): array
    {
        return DB::transaction(function () use ($user, $bearer, $client): array {
            $legacy = PersonalAccessToken::query()->whereKey($bearer->getKey())->lockForUpdate()->first();

            if ($legacy === null) {
                throw self::expired();
            }

            if (RefreshToken::query()->where('access_token_id', $legacy->getKey())->exists()) {
                throw new ApiException('session_already_refreshable', 'هذه الجلسة تتجدد تلقائيًا بالفعل', 409);
            }

            // Upgraded a moment ago by a request racing this one: the same pair.
            if ($legacy->expires_at !== null && ($held = $this->decode(Cache::get(self::upgradeKey($legacy)))) !== null) {
                return self::withRemaining($held);
            }

            $session = $this->mint($user, $client)['session'];

            // Only the first upgrade starts the clock. One that finds the held pair gone
            // is answered with a pair of its own, but never buys the old token more time.
            if ($legacy->expires_at === null) {
                $legacy->forceFill(['expires_at' => now()->addSeconds(self::GRACE_SECONDS)])->save();

                Cache::put(self::upgradeKey($legacy), Crypt::encryptString(json_encode($session)), self::GRACE_SECONDS);
            }

            return $session;
        });
    }

    /** The one refusal, whatever was wrong with the token. */
    public static function expired(): ApiException
    {
        return new ApiException('session_expired', 'انتهت الجلسة. سجّل الدخول مرة أخرى.', 401);
    }

    /**
     * A new pair: an access token, and a refresh token in `$family` — a new family when
     * this is a sign-in, the presented token's when it is a renewal.
     *
     * @return array{row: RefreshToken, session: array<string, mixed>}
     */
    private function mint(User $owner, SessionClient $client, ?string $family = null): array
    {
        $minutes = Sessions::accessMinutes();
        $days = Sessions::idleDays($owner);
        $accessExpiresAt = now()->addMinutes($minutes)->startOfSecond();
        $refreshExpiresAt = now()->addDays($days)->startOfSecond();

        $access = $owner->createToken($owner->type->value, [$owner->type->value], $accessExpiresAt);
        $plain = Str::random(self::REFRESH_TOKEN_LENGTH);

        $row = RefreshToken::query()->create([
            'user_id' => $owner->id,
            'family' => $family ?? (string) Str::uuid(),
            'token_hash' => hash('sha256', $plain),
            'access_token_id' => $access->accessToken->getKey(),
            'client' => $client->app,
            'device_id' => $client->deviceId,
            'ip' => $client->ip,
            'user_agent' => $client->userAgent,
            'expires_at' => $refreshExpiresAt,
        ]);

        return ['row' => $row, 'session' => [
            'token' => $access->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $minutes * 60,
            'expires_at' => $accessExpiresAt->toIso8601String(),
            'refresh_token' => $plain,
            'refresh_expires_in' => $days * 86400,
            'refresh_expires_at' => $refreshExpiresAt->toIso8601String(),
        ]];
    }

    /**
     * Retires the presented token into a new pair in the same family.
     *
     * Its access token goes with it — a renewal is also the end of the old hour — and the
     * new pair is held, encrypted, on the retired row for the grace window.
     *
     * @return array<string, mixed>
     */
    private function rotate(RefreshToken $row, User $owner, SessionClient $client): array
    {
        ['row' => $next, 'session' => $session] = $this->mint($owner, $client, $row->family);

        if ($row->access_token_id !== null) {
            PersonalAccessToken::query()->whereKey($row->access_token_id)->delete();
        }

        $row->forceFill([
            'rotated_at' => now(),
            'last_used_at' => now(),
            'replaced_by_id' => $next->id,
            'grace_payload' => Crypt::encryptString(json_encode($session)),
        ])->save();

        // Whatever an earlier renewal in this sign-in still holds past its own window.
        RefreshToken::query()
            ->where('family', $row->family)
            ->whereKeyNot($row->id)
            ->whereNotNull('grace_payload')
            ->where('rotated_at', '<', now()->subSeconds(self::GRACE_SECONDS))
            ->update(['grace_payload' => null]);

        return $session;
    }

    /**
     * The pair this row was rotated into, while its grace window is open.
     *
     * @return array<string, mixed>|null
     */
    private function replay(RefreshToken $row): ?array
    {
        if ($row->grace_payload === null) {
            return null;
        }

        if ($row->rotated_at->lt(now()->subSeconds(self::GRACE_SECONDS))) {
            // Stale: never answered from again, so not kept either.
            $row->forceFill(['grace_payload' => null])->save();

            return null;
        }

        $session = $this->decode($row->grace_payload);

        if ($session === null) {
            return null;
        }

        $row->forceFill(['last_used_at' => now()])->save();

        return self::withRemaining($session);
    }

    /** A successor nobody received: revoked, with its access token. */
    private function withdraw(RefreshToken $successor): void
    {
        if ($successor->access_token_id !== null) {
            PersonalAccessToken::query()->whereKey($successor->access_token_id)->delete();
        }

        $successor->forceFill(['revoked_at' => now(), 'grace_payload' => null])->save();
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $encrypted): ?array
    {
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $session = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            // A payload the current key cannot read — an `APP_KEY` rotated inside the
            // window. Not a session; the caller falls through to the paths that do not
            // need it.
            return null;
        }

        return is_array($session) ? $session : null;
    }

    /**
     * The same pair, with `expires_in` counted from now rather than from when it was
     * minted — the tokens and the instants are identical, and a client that adds
     * `expires_in` to its own clock still lands on the right one.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private static function withRemaining(array $session): array
    {
        $now = now()->getTimestamp();

        $session['expires_in'] = max(0, Carbon::parse($session['expires_at'])->getTimestamp() - $now);
        $session['refresh_expires_in'] = max(0, Carbon::parse($session['refresh_expires_at'])->getTimestamp() - $now);

        return $session;
    }

    private static function upgradeKey(PersonalAccessToken $legacy): string
    {
        return 'session-upgrade:'.$legacy->getKey();
    }
}
