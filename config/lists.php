<?php

/*
 * Batch 68 — ADMIN-LISTS.md §5 and §6: the public read cache and the per-account
 * throttles. Every value here has a default that is right for production; the env keys
 * exist so an incident can be answered without a deploy.
 */
return [

    'http_cache' => [
        // Off: every public read is built from the database again, as before batch 68.
        'enabled' => (bool) env('HTTP_CACHE_ENABLED', true),
        // On: `public` instead of `private` on the language-only answers, so a shared cache
        // may keep them. Off by default: production sits behind Hostinger's CDN (`server:
        // hcdn`), which has already been measured mangling a response header (doc 65 — it
        // forwarded one CSP directive of many), and nobody has measured whether it honours
        // `Vary: Accept-Language` / `Vary: Authorization`. A CDN that ignores `Vary` would
        // hand an English body to an Arabic phone. The server-side store and the ETag/304
        // still do the work with this off; turn it on only after measuring the CDN.
        'public' => (bool) env('HTTP_CACHE_PUBLIC', false),
    ],

    /*
     * Requests per minute per signed-in account, by account type. Sized for a screen that
     * polls every 15 s (four lists at once is 16/min) with room for navigation bursts.
     */
    'throttle' => [
        // Where the counters live. Not the default store: on this host that is the
        // database, and counting every signed-in request there is a locked write per
        // poll (see `ThrottlePerAccount`). `array` under test, so no count survives a run.
        'store' => env('THROTTLE_STORE', env('APP_ENV') === 'testing' ? 'array' : 'file'),
        'admin' => (int) env('THROTTLE_ADMIN_PER_MINUTE', 300),
        'restaurant' => (int) env('THROTTLE_PLACE_PER_MINUTE', 240),
        'driver' => (int) env('THROTTLE_CAPTAIN_PER_MINUTE', 240),
        'customer' => (int) env('THROTTLE_CUSTOMER_PER_MINUTE', 180),
        // `GET /search` — public, and the one catalogue read that scans every name.
        'search_per_account' => (int) env('THROTTLE_SEARCH_PER_MINUTE', 120),
        'search_per_ip' => (int) env('THROTTLE_SEARCH_PER_IP_PER_MINUTE', 300),
    ],

];
