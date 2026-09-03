<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may read Telescope on a deployed environment.
 *
 * Telescope's stock gate answers `Gate::check('viewTelescope', $user)`, and this
 * application has no web session at all — every client authenticates with a Sanctum
 * bearer token against `api/*`. So the stock gate denies everyone, including the
 * operator, and the panel is unreachable in production no matter what is configured.
 *
 * A shared secret is the honest replacement. `?telescope_token=…` once, and the token
 * is kept in an `httpOnly` cookie for the rest of the session, so Telescope's own
 * XHR calls to `telescope/telescope-api/*` carry it without a query string.
 *
 * A wrong or missing token is **404**, not 403 — the same rule D-52 applies to
 * out-of-scope rows. A 403 would confirm that a panel is there to be attacked.
 */
class AuthorizeTelescope
{
    /** Eight hours: long enough for a test session, short enough not to be a key. */
    private const COOKIE_MINUTES = 480;

    private const COOKIE = 'telescope_token';

    public function handle(Request $request, Closure $next): Response
    {
        // A developer's own machine needs no secret; nothing is exposed there.
        if (app()->environment('local')) {
            return $next($request);
        }

        $expected = (string) config('telescope.auth_token', '');

        // An unset token closes the door rather than opening it. Deploying with
        // TELESCOPE_ENABLED=true and no token must not publish every request body
        // the API receives to the whole internet.
        if ($expected === '') {
            abort(404);
        }

        $fromQuery = $request->query(self::COOKIE);
        $provided = is_string($fromQuery) ? $fromQuery : $request->cookie(self::COOKIE);

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(404);
        }

        $response = $next($request);

        if (is_string($fromQuery)) {
            $response->headers->setCookie(new Cookie(
                name: self::COOKIE,
                value: $expected,
                expire: time() + self::COOKIE_MINUTES * 60,
                path: '/',
                secure: $request->isSecure(),
                httpOnly: true,
                sameSite: Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }
}
