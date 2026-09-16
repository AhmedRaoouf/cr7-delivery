<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ApiResponse;
use App\Support\Auth\Sessions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * D-73 — a blocked account is blocked **now**, not at its next login.
 *
 * `users.is_active` has existed since the first migration and was read in exactly two
 * places, `PasswordLoginAction` and `VerifyOtpAction`. Both are logins. So suspending
 * an account suspended nothing that was already signed in: the phone in a blocked
 * captain's hand kept accepting offers, and the only thing that eventually stopped it
 * was the token expiring on its own. `UserController::suspend` deleted the admin's
 * tokens by hand, which is the right instinct and covers exactly one of the four user
 * types.
 *
 * **423, not 403.** `403` in this API means «you are signed in and this is not yours»,
 * and the app treats it as a screen-level permission problem. A blocked account is an
 * account-level state, and the client already maps `423` to `ApiErrorCodes.accountSuspended`
 * — the one status that signs the app out and shows the reason.
 *
 * The reason travels with it. An account that stops working with no explanation is a
 * support call; one that says «تم إيقاف الحساب — السبب: …» is not.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isBlocked()) {
            // Any token that reaches here belongs to an account that may no longer
            // hold one. Revoking on the way past means a blocked account cannot keep
            // knocking, and costs one delete per blocked request rather than a sweep.
            // Refresh tokens included (D-120) — or the next renewal would bring the
            // session straight back the moment the account is unblocked.
            Sessions::revokeAll($user);

            return ApiResponse::error('account_suspended', self::sentence($user), 423);
        }

        return $next($request);
    }

    /**
     * What a blocked account is told, here and by `POST /auth/refresh` — the one route a
     * blocked account still reaches without passing through this middleware.
     */
    public static function sentence(User $user): string
    {
        return $user->blocked_reason
            ? 'تم إيقاف حسابك — السبب: '.$user->blocked_reason
            : 'تم إيقاف حسابك — يرجى التواصل مع الدعم';
    }
}
