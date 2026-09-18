<?php

/*
|--------------------------------------------------------------------------
| Transport and response hardening (batch 67, the security audit)
|--------------------------------------------------------------------------
|
| Read by App\Http\Middleware\SecurityHeaders and config/cors.php. Every value
| has a safe default, so a deployment whose `.env` never mentions any of these
| is already hardened; the variables exist so a staging host can loosen one
| thing without a code change.
|
*/

return [

    /*
     * Strict-Transport-Security. On by default only in production: a browser that
     * has seen it refuses plain HTTP to the host for `max_age` seconds, which is
     * exactly what is wanted on cr7-delivery.com and exactly what is not wanted on
     * a developer's http://127.0.0.1.
     *
     * `include_subdomains` covers dashboard. and admin. (both HTTPS-only already).
     * `preload` stays off: it is a submission to the browsers' built-in list that
     * takes months to undo, and it needs every subdomain — mail., webmail., any
     * future one — to be HTTPS first. That is an operator decision.
     */
    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS', env('APP_ENV') === 'production'),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 63072000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
        'preload' => (bool) env('SECURITY_HSTS_PRELOAD', false),
    ],

    /*
     * The only browsers that ever talk to this API are the two dashboards, and they
     * do it from their own Node server through the BFF (D-69) — the browser never
     * holds a token and never calls this origin with fetch(). The mobile app is not
     * a browser and never sends a CORS preflight. So the allow-list is the two
     * dashboard origins and nothing else, and even those are a fallback rather than
     * something a client relies on today. `*` is never correct here.
     */
    'cors_allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'https://dashboard.cr7-delivery.com,https://admin.cr7-delivery.com',
        )),
    ))),

    /*
     * The largest page any list endpoint will answer. The biggest page a client asks
     * for today is 200 (`/admin/restaurants` on ADM-10's picker); `per_page=1000000`
     * used to be answered in full.
     */
    'max_per_page' => (int) env('SECURITY_MAX_PER_PAGE', 200),

];
