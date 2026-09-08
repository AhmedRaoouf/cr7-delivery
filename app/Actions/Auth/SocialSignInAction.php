<?php

namespace App\Actions\Auth;

use App\Enums\SocialProvider;
use App\Exceptions\ApiException;
use App\Models\SocialAccount;
use App\Models\SocialLinkTicket;
use App\Support\Social\IdentityTokenVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «المتابعة باستخدام Apple / Google», first half.
 *
 * The client sends the `id_token` the platform SDK gave it and nothing else that matters:
 * the identity is whatever survives `IdentityTokenVerifier`, never what the request claims.
 *
 * Two outcomes, and the response says which:
 *
 * * **`signed_in`** — this exact `(provider, sub)` is already attached to an account, so
 *   there is nothing left to prove. No code, no phone screen.
 * * **`needs_phone`** — a subject nobody has seen. **Neither provider returns a phone
 *   number** (see the migration), and `users.phone` is how the restaurant and the captain
 *   reach the customer, so an account cannot be created from this alone. A ticket is
 *   issued instead and `CUS-07a` asks for the number once.
 *
 * The name is the awkward one. Google puts it on the token. Apple does not — it hands the
 * name to the *client* on the **first authorisation only**, and every later token for the
 * same person carries no name at all. So the client is allowed to send one, it is treated
 * as a display string and never as identity, and it is written down here on the one pass
 * where it exists.
 */
class SocialSignInAction
{
    /** Long enough to type a phone number and read a text message; short enough to be worthless if scraped. */
    public const LINK_TICKET_MINUTES = 15;

    public function __construct(private readonly IdentityTokenVerifier $verifier) {}

    /**
     * @return array{status: 'signed_in', user: \App\Models\User, token: string}|array{status: 'needs_phone', ticket: SocialLinkTicket, name: ?string, email: ?string}
     */
    public function execute(SocialProvider $provider, string $idToken, ?string $clientName = null): array
    {
        $identity = $this->verifier->verify($provider, $idToken);
        $name = $identity->name ?? $this->sanitiseName($clientName);

        $link = SocialAccount::query()
            ->with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', $identity->subject)
            ->first();

        if ($link?->user !== null) {
            return $this->signIn($link, $name, $identity->email, $identity->emailVerified);
        }

        // A row whose user was deleted is a dead identity, not a sign-in. Clear it so the
        // unique index does not refuse the ticket that is about to replace it.
        $link?->delete();

        $ticket = SocialLinkTicket::create([
            'provider' => $provider,
            'provider_user_id' => $identity->subject,
            'email' => $identity->email,
            'name' => $name,
            'email_verified' => $identity->emailVerified,
            'token' => Str::random(64),
            'expires_at' => now()->addMinutes(self::LINK_TICKET_MINUTES),
        ]);

        return [
            'status' => 'needs_phone',
            'ticket' => $ticket,
            'name' => $name,
            'email' => $identity->email,
        ];
    }

    /**
     * @return array{status: 'signed_in', user: \App\Models\User, token: string}
     */
    private function signIn(SocialAccount $link, ?string $name, ?string $email, bool $emailVerified): array
    {
        $user = $link->user;

        if (! $user->isCustomer()) {
            // One phone is one role (D-45), and one identity is one account. Named rather
            // than merely refused, exactly like the OTP path.
            throw new ApiException(
                'wrong_client',
                "هذا الحساب مسجل ك{$user->type->label()} — سجّل الدخول بكلمة المرور",
                403,
                meta: ['account_type' => $user->type->value],
            );
        }

        if (! $user->is_active) {
            throw new ApiException('account_suspended', 'هذا الحساب موقوف', 423);
        }

        return DB::transaction(function () use ($link, $user, $name, $email, $emailVerified): array {
            $link->forceFill(array_filter([
                'name' => $name ?? $link->name,
                'email' => $email ?? $link->email,
            ], static fn ($value) => $value !== null) + [
                'email_verified' => $emailVerified || $link->email_verified,
                'last_used_at' => now(),
            ])->save();

            // Apple's name reaches us once. If the account was created before we had it —
            // the customer skipped `CUS-09` — this is the only chance to write it down.
            if (blank($user->name) && filled($name)) {
                $user->forceFill(['name' => $name])->save();
            }

            $user->forceFill(['last_login_at' => now()])->save();

            return [
                'status' => 'signed_in',
                'user' => $user,
                'token' => $user->createToken('customer', [$user->type->value])->plainTextToken,
            ];
        });
    }

    private function sanitiseName(?string $name): ?string
    {
        $trimmed = trim((string) $name);

        return $trimmed === '' ? null : Str::limit($trimmed, 117, '');
    }
}
