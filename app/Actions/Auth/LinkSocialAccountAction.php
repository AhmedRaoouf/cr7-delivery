<?php

namespace App\Actions\Auth;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\SocialAccount;
use App\Models\SocialLinkTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «المتابعة باستخدام Apple / Google», second half — `CUS-07a`.
 *
 * The customer is holding a ticket that says «this Apple / Google identity was verified»
 * and has just answered an SMS code on the number they typed. This turns the two into one
 * account.
 *
 * It does **not** re-implement the phone half. `VerifyOtpAction` with `purpose = login` is
 * exactly the behaviour wanted and is reused verbatim: it checks the code, its expiry and
 * its attempt count, creates the customer if the number is new, refuses a number that
 * belongs to a captain / restaurant / admin with `wrong_client` and names which type it
 * is, refuses a suspended account, and issues the session. `RegisterCustomerAction` is the
 * other half of «إنشاء حساب» and is deliberately *not* used: it exists to turn a password
 * into an account, and this flow has no password at all.
 *
 * Everything runs in one transaction, so a phone that is refused mid-way leaves neither an
 * account nor a spent ticket behind.
 */
class LinkSocialAccountAction
{
    public function __construct(private readonly VerifyOtpAction $verifyOtp) {}

    /** @return array{user: User, token: string, is_new_user: bool} */
    public function execute(string $linkTicket, string $phone, string $code): array
    {
        return DB::transaction(function () use ($linkTicket, $phone, $code): array {
            $ticket = SocialLinkTicket::query()
                ->where('token', $linkTicket)
                ->lockForUpdate()
                ->first();

            if ($ticket === null || ! $ticket->isUsable()) {
                throw new ApiException(
                    'social_link_expired',
                    'انتهت صلاحية التحقق — أعد تسجيل الدخول عبر مزوّد الخدمة',
                    422,
                );
            }

            // Fifteen minutes is long enough for the same identity to be linked from
            // another device. Without this the unique index answers that race with a 500.
            $alreadyLinked = SocialAccount::query()
                ->where('provider', $ticket->provider)
                ->where('provider_user_id', $ticket->provider_user_id)
                ->exists();

            if ($alreadyLinked) {
                throw new ApiException(
                    'provider_already_linked',
                    "حساب {$ticket->provider->label()} هذا مرتبط بحساب آخر بالفعل",
                    409,
                    meta: ['provider' => $ticket->provider->value],
                );
            }

            $result = $this->verifyOtp->execute($phone, $code, OtpPurpose::Login);

            /** @var User $user */
            $user = $result['user'];

            // An account keeps at most one identity per provider. Replacing it silently
            // would move somebody's sign-in onto a Google account they were merely
            // signed into on that phone.
            $hasProvider = $user->socialAccounts()
                ->where('provider', $ticket->provider)
                ->exists();

            if ($hasProvider) {
                throw new ApiException(
                    'provider_conflict',
                    "هذا الرقم مرتبط بحساب {$ticket->provider->label()} آخر",
                    409,
                    meta: ['provider' => $ticket->provider->value],
                );
            }

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $ticket->provider,
                'provider_user_id' => $ticket->provider_user_id,
                'email' => $ticket->email,
                'name' => $ticket->name,
                'email_verified' => $ticket->email_verified,
                'last_used_at' => now(),
            ]);

            $this->adoptProfile($user, $ticket);

            $ticket->forceFill(['consumed_at' => now()])->save();

            return [
                'user' => $user->refresh(),
                'token' => $result['token'],
                'is_new_user' => $result['is_new_user'],
            ];
        });
    }

    /**
     * The provider's name and email fill an empty account and never overwrite a full one —
     * a customer who typed their own name on `CUS-09` keeps it.
     *
     * The email is only adopted when the provider says it verified it and nobody else has
     * it: `users.email` is unique and is a sign-in identifier for the password flow, so
     * taking an unverified one would hand somebody a route into another account.
     */
    private function adoptProfile(User $user, SocialLinkTicket $ticket): void
    {
        $changes = [];

        if (blank($user->name) && filled($ticket->name)) {
            $changes['name'] = $ticket->name;
        }

        if (blank($user->email) && filled($ticket->email) && $ticket->email_verified) {
            $taken = User::query()->where('email', $ticket->email)->whereKeyNot($user->id)->exists();

            if (! $taken) {
                $changes['email'] = $ticket->email;
                $changes['email_verified_at'] = now();
            }
        }

        if ($changes !== []) {
            $user->forceFill($changes)->save();
        }
    }
}
