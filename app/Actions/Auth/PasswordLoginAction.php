<?php

namespace App\Actions\Auth;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * FR-AUTH-04/05/10 — password login, and the shell it is allowed to open.
 *
 * `$expected` is a set rather than one type because the mobile binary is two shells
 * (D-45) and, since 2026-09-07, one login screen. The operator's ruling: «مش عايزه يختار
 * هو customer ولا driver» — the phone and password decide, and the app routes on the
 * `type` that comes back. The dashboards still pass exactly one type each, so a captain's
 * password on `admin.` is refused by the same line it always was.
 */
class PasswordLoginAction
{
    /**
     * @param  UserType|list<UserType>  $expected
     * @return array{user: User, token: string}
     */
    public function execute(string $identifier, string $password, UserType|array $expected): array
    {
        $allowed = is_array($expected) ? $expected : [$expected];
        // `whereLogin` normalises: `+20 109 000 9599` and `01090009599` are the same
        // account, and comparing the raw string made the second one unreachable.
        $user = User::query()->whereLogin($identifier)->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            throw new ApiException('invalid_credentials', 'بيانات الدخول غير صحيحة', 422);
        }

        if (! in_array($user->type, $allowed, true)) {
            throw new ApiException(
                'wrong_client',
                "هذا الحساب ({$user->type->label()}) لا يستخدم هذه الواجهة",
                403,
                meta: ['account_type' => $user->type->value],
            );
        }

        if (! $user->is_active) {
            throw new ApiException('account_suspended', 'هذا الحساب موقوف', 423);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($user->type->value, [$user->type->value])->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
