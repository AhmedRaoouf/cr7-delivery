<?php

namespace App\Actions\Auth;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\Auth\SessionClient;
use App\Support\Auth\SessionIssuer;
use App\Support\SettingsCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * «إنشاء حساب» — the second half of registration, or all of it.
 *
 * **With a code** (`auth.register_requires_otp`, the default): the customer has already
 * proved the phone is theirs (`VerifyOtpAction` with `purpose = register`) and is holding
 * the token that says so. This turns that token, a name and a password into an account
 * and a session, once. The token is destroyed inside the same transaction that creates the
 * user, so two taps on a slow «تسجيل» button cannot produce two accounts — the second one
 * finds nothing to spend and is told the link expired rather than colliding on
 * `users.phone` with a 500.
 *
 * **Without a code** (D-106 — the switch off on `ADM-46`): phone, name and password in one
 * request. The operator's case is a gateway whose Sender ID is not approved yet: the
 * alternative is a sign-up nobody can finish. The account is created with
 * `phone_verified_at` null, which is the truth — nothing proved the number.
 *
 * A token that arrives is always honoured, whatever the switch says: an app that verified
 * a phone a minute before the operator flipped it should not have to start again.
 */
class RegisterCustomerAction
{
    public function __construct(private readonly SessionIssuer $sessions) {}

    /**
     * `session` is merged into the response's `data` — see {@see SessionIssuer::signIn()}.
     *
     * @return array{user: User, session: array<string, mixed>}
     */
    public function execute(
        ?string $registrationToken,
        string $name,
        string $password,
        ?string $phone = null,
        SessionClient $client = new SessionClient,
    ): array {
        if (filled($registrationToken)) {
            return $this->withToken($registrationToken, $name, $password, $client);
        }

        if (SettingsCatalog::bool('auth.register_requires_otp')) {
            throw new ApiException('otp_required', 'يلزم تأكيد رقم الهاتف أولًا', 422);
        }

        if (blank($phone)) {
            throw new ApiException('validation_failed', 'أدخل رقم الهاتف', 422, errors: ['phone' => ['أدخل رقم الهاتف']]);
        }

        return $this->withoutCode($phone, $name, $password, $client);
    }

    /**
     * Said the same way on both paths, and without the account's type.
     *
     * It used to name the kind of account the number already was — «مسجل كحساب كابتن» —
     * and carry it in `meta.account_type`. That told anybody who typed a number whether it
     * belonged to a captain, a place or an admin, before any password was asked for.
     */
    public static function phoneTaken(): ApiException
    {
        return new ApiException('phone_taken', 'هذا الرقم مسجل بالفعل — سجّل الدخول بكلمة المرور', 409);
    }

    /** @return array{user: User, session: array<string, mixed>} */
    private function withToken(string $registrationToken, string $name, string $password, SessionClient $client): array
    {
        return DB::transaction(function () use ($registrationToken, $name, $password, $client): array {
            $otp = OtpCode::query()
                ->where('follow_up_token', $registrationToken)
                ->lockForUpdate()
                ->first();

            if ($otp === null || ! $otp->followUpIsUsable()) {
                throw new ApiException(
                    'registration_expired',
                    'انتهت صلاحية التحقق — أعد إرسال رمز التحقق',
                    422,
                );
            }

            // Re-checked here, not only in `VerifyOtpAction`. Fifteen minutes is long
            // enough for the same number to be registered from another device, and the
            // unique index would otherwise answer that race with a 500.
            if (User::query()->where('phone', $otp->phone)->exists()) {
                throw self::phoneTaken();
            }

            $created = $this->create($otp->phone, $name, $password, verified: true, client: $client);

            $otp->forceFill(['follow_up_token' => null, 'follow_up_expires_at' => null])->save();

            return $created;
        });
    }

    /** @return array{user: User, session: array<string, mixed>} */
    private function withoutCode(string $phone, string $name, string $password, SessionClient $client): array
    {
        if (User::query()->withTrashed()->where('phone', $phone)->exists()) {
            throw self::phoneTaken();
        }

        try {
            return DB::transaction(fn (): array => $this->create($phone, $name, $password, verified: false, client: $client));
        } catch (UniqueConstraintViolationException) {
            // Two taps on «تسجيل» from two devices, the second one losing the race on the
            // unique index. The answer is the same as for a number that was already here.
            throw self::phoneTaken();
        }
    }

    /** @return array{user: User, session: array<string, mixed>} */
    private function create(string $phone, string $name, string $password, bool $verified, SessionClient $client): array
    {
        // `forceFill`, not `create`: `phone_verified_at` and `last_login_at` are not in
        // `$fillable` — deliberately, so no client request can ever set them — and a
        // mass-assign silently dropped both, leaving a brand-new account looking
        // unverified to every guard that reads it.
        $user = new User;
        $user->forceFill([
            'name' => $name,
            'phone' => $phone,
            'password' => Hash::make($password),
            'type' => UserType::Customer,
            'locale' => 'ar',
            'theme' => 'dark',
            'is_active' => true,
            'must_change_password' => false,
            'phone_verified_at' => $verified ? now() : null,
            'last_login_at' => now(),
        ])->save();

        return ['user' => $user, 'session' => $this->sessions->signIn($user, $client)];
    }
}
