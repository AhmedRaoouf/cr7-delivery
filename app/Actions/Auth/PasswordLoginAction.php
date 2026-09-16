<?php

namespace App\Actions\Auth;

use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Auth\SessionClient;
use App\Support\Auth\SessionIssuer;
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
    public function __construct(private readonly SessionIssuer $sessions) {}

    /**
     * `session` is merged into the response's `data` — see {@see SessionIssuer::signIn()}.
     *
     * @param  UserType|list<UserType>  $expected
     * @return array{user: User, session: array<string, mixed>}
     */
    public function execute(string $identifier, string $password, UserType|array $expected, SessionClient $client = new SessionClient): array
    {
        $allowed = is_array($expected) ? $expected : [$expected];
        // `whereLogin` normalises: `+20 109 000 9599` and `01090009599` are the same
        // account, and comparing the raw string made the second one unreachable.
        $user = User::query()->whereLogin($identifier)->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            throw new ApiException('invalid_credentials', 'بيانات الدخول غير صحيحة', 422);
        }

        /*
         * The wrong shell is a wrong sign-in, and says so in exactly those words.
         *
         * Operator ruling 2026-09-11: «خلى role ال admin بس اللي يدخل الادمن و role
         * المطاعم اللي يدخل المطاعم. لو role دخل في مكان غلط قوله بيانات دخول خاطئه».
         * The old answer — 403 `wrong_client` naming the account's own type, and a
         * dashboard that then offered to carry you to the other one — told an anonymous
         * caller that this phone *is* an admin account and that its password was right.
         * It is the same refusal as a wrong password now, byte for byte, and no meta
         * to distinguish them.
         */
        if (! in_array($user->type, $allowed, true)) {
            throw new ApiException('invalid_credentials', 'بيانات الدخول غير صحيحة', 422);
        }

        if (! $user->is_active) {
            throw new ApiException('account_suspended', 'هذا الحساب موقوف', 423);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return ['user' => $user, 'session' => $this->sessions->signIn($user, $client)];
    }
}
