<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batch 67 — the response headers every answer from this origin carries.
 *
 * Measured on production 2026-09-18, before this existed: `x-powered-by: PHP/8.3.33`
 * on every response, and no `Strict-Transport-Security`, `X-Content-Type-Options`,
 * `X-Frame-Options` or `Referrer-Policy` on any of them. Hostinger's CDN (`server:
 * hcdn`) forwards those four from the origin — the Next.js dashboards on the same CDN
 * receive all of them — but it **replaces** any `Content-Security-Policy` with its own
 * `upgrade-insecure-requests`, so the CSP below is defence for a deployment without
 * that CDN (or the day it stops rewriting), not something production can rely on.
 *
 * Registered as **global** middleware, so it also covers the answers no route
 * produced: a 404 for a path that does not exist, a 405, and the exception pages.
 *
 * What each header is for:
 *
 *   * `X-Content-Type-Options: nosniff` — a browser must not guess that a
 *     user-uploaded file served as `application/octet-stream` is HTML or script.
 *   * `X-Frame-Options: DENY` — nothing on this origin is meant to be framed; the
 *     Telescope panel and the share pages included.
 *   * `Referrer-Policy: no-referrer` — a share link or a Telescope URL carrying a
 *     token in its query string is never leaked to the next site in a Referer.
 *   * `Cross-Origin-Resource-Policy: same-site` — images here are embedded by the
 *     two dashboards (dashboard. and admin., the same site) and nobody else.
 *   * `Strict-Transport-Security` — production only; see config/security.php.
 *   * `Cache-Control: no-store` on anything answered to a bearer token — a JSON body
 *     holding one person's orders and phone numbers must never be kept by a proxy
 *     or the CDN.
 */
class SecurityHeaders
{
    /**
     * The API answers JSON and files; it never renders a document that needs to load
     * anything. A CSP of nothing is therefore the whole policy.
     */
    private const API_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // PHP adds `X-Powered-By` at the SAPI level when `expose_php` is on (it is, on
        // the host, and it cannot be switched off from `.user.ini`). `header_remove()`
        // takes it out of PHP's own header list before anything is sent; the second
        // call covers a response object that copied it in.
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        $headers = $response->headers;
        $headers->remove('X-Powered-By');
        $headers->remove('Server');

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if ($request->is('api/*')) {
            $headers->set('Content-Security-Policy', self::API_CSP);

            if ($request->bearerToken() !== null) {
                $headers->set('Cache-Control', 'no-store, private');
                $headers->set('Pragma', 'no-cache');
            }
        }

        if (config('security.hsts.enabled')) {
            $value = 'max-age='.max(0, (int) config('security.hsts.max_age', 63072000));

            if (config('security.hsts.include_subdomains')) {
                $value .= '; includeSubDomains';
            }

            if (config('security.hsts.preload')) {
                $value .= '; preload';
            }

            $headers->set('Strict-Transport-Security', $value);
        }

        return $response;
    }
}
