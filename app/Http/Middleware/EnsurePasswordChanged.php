<?php

namespace App\Http\Middleware;

use App\Enums\UserType;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batch 67 — a temporary password opens the door to the password change and to
 * nothing else (FR-AUTH-10, doc 08's `must_change_password` error code).
 *
 * Before this, `must_change_password` was enforced by the clients alone: the
 * dashboards redirect to `/change-password` and the app pushes the forced-change
 * screen. The API itself accepted the office-issued temporary password for every
 * route — so whoever else saw that password (it is handed over by phone, on paper, on
 * WhatsApp) could sign in with it and accept orders, edit a menu or run the admin
 * console by calling the API directly, and could keep doing so for as long as the
 * owner had not yet changed it.
 *
 * **Staff accounts only** — captain, place and admin, the three the office creates
 * and resets. A customer chose their own password (or signs in by SMS code), and the
 * one path that sets the flag on a customer (`ModerateAccountAction::reset`) is
 * followed by the same forced-change screen in the app, which uses only the routes
 * below; exempting customers keeps an OTP sign-in from being walled by a flag it
 * never used.
 *
 * The allow-list is exactly what the three clients call between sign-in and the
 * completed change — measured in `cr7-dashboards` (`auth-actions.ts`,
 * `change-password/page.tsx`) and `cr7_delivery` (`driver_login_screen.dart`, whose
 * captain profile reads `/auth/me`, `/driver/me` and `/driver/stats` before it decides
 * to show the forced change). Everything else answers 403 `must_change_password`.
 */
class EnsurePasswordChanged
{
    /**
     * `METHOD path` pairs, relative to `api/v1/`. `*` matches any method.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const ALLOWED = [
        ['GET', 'auth/me'],
        ['GET', 'auth/me/notification-settings'],
        ['POST', 'auth/password/change'],
        ['POST', 'auth/password/change/send-code'],
        ['POST', 'auth/logout'],
        ['POST', 'auth/logout-all'],
        ['POST', 'auth/session/upgrade'],
        ['*', 'devices'],
        ['GET', 'driver/me'],
        ['GET', 'driver/stats'],
        ['GET', 'restaurant/me'],
        ['GET', 'notifications/unread-count'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password || $user->type === UserType::Customer) {
            return $next($request);
        }

        if ($this->allowed($request)) {
            return $next($request);
        }

        return ApiResponse::error(
            'must_change_password',
            'يجب تغيير كلمة المرور المؤقتة قبل المتابعة',
            403,
        );
    }

    private function allowed(Request $request): bool
    {
        $path = preg_replace('#^api/v1/#', '', trim($request->path(), '/'));
        $method = strtoupper($request->method());

        foreach (self::ALLOWED as [$allowedMethod, $allowedPath]) {
            if ($path === $allowedPath && ($allowedMethod === '*' || $allowedMethod === $method)) {
                return true;
            }
        }

        return false;
    }
}
