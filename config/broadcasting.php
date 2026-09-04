<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | `null` is the safe default: with no Pusher credentials configured the app
    | behaves exactly as it did before broadcasting existed — every event is
    | swallowed, nothing throws, and the dashboards fall back to their polling
    | safety net. Set BROADCAST_CONNECTION=pusher only once the four PUSHER_*
    | values are in .env.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER', 'eu'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'eu').'.pusher.com',
                'port' => (int) env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
                // The Pusher REST call happens inside the web request (see
                // App\Events\OrderChanged — ShouldBroadcastNow), so it must never be
                // allowed to hold a request open. Six seconds is generous for a POST
                // that normally answers in well under one.
                'timeout' => (int) env('PUSHER_TIMEOUT', 6),
            ],
            'client_options' => [
                'timeout' => (int) env('PUSHER_TIMEOUT', 6),
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
