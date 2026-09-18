<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| Batch 67. This file did not exist, so Laravel's built-in default applied:
| `allowed_origins => ['*']` on `api/*` — measured on production as
| `access-control-allow-origin: *` on every answer, and a preflight from any
| website on earth answered 204 with the method and headers it asked for.
|
| Nothing needs CORS here. The mobile app is not a browser, and the two web
| dashboards call this API from their own Node server through the BFF (D-69) — the
| browser never holds a token and never fetches this origin. The allow-list is the
| two dashboard origins as a fallback (`CORS_ALLOWED_ORIGINS`, comma-separated),
| never `*` — a `*` in the variable is dropped — and never with credentials.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Read from the environment here, not from config/security.php: configuration
    // files load in alphabetical order, so `cors` is built before `security` exists.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'https://dashboard.cr7-delivery.com,https://admin.cr7-delivery.com',
        )),
    ), fn (string $origin) => $origin !== '' && $origin !== '*')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept', 'Accept-Language', 'Authorization', 'Content-Type', 'Idempotency-Key',
        'X-App', 'X-App-Version', 'X-Auth-Refresh', 'X-Device-Id', 'X-Platform', 'X-Role',
        'X-Requested-With',
    ],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,

];
