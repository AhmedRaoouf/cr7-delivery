<?php

namespace App\Actions\Auth;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** FR-AUTH-04/05/10 — driver, restaurant and admin accounts: Admin-created, password login, forced change on first use. */
class PasswordLoginAction
{
    /** @return array{user: User, token: string} */
    public function execute(string $identifier, string $password, UserType $expectedType): array
    {
        // `whereLogin` normalises: `+20 109 000 9599` and `01090009599` are the same
        // account, and comparing the raw string made the second one unreachable.
        $user = User::query()->whereLogin($identifier)->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            throw new ApiException('invalid_credentials', 'بيانات الدخول غير صحيحة', 422);
        }

        if ($user->type !== $expectedType) {
            throw new ApiException('wrong_client', 'هذا الحساب لا يستخدم هذه الواجهة', 403);
        }

        if (! $user->is_active) {
            throw new ApiException('account_suspended', 'هذا الحساب موقوف', 423);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($expectedType->value, [$user->type->value])->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
