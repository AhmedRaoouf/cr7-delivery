<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // 'log' (dev — writes the OTP to storage/logs/laravel.log) or 'sms_misr' (real gateway).
    'otp_channel' => env('OTP_CHANNEL', 'log'),

    /*
     * Firebase Cloud Messaging — doc 11's push channel.
     *
     * `credentials` is a path to the service-account JSON downloaded from
     * Firebase → Project settings → Service accounts. It is a **private key**:
     * it must never sit under `public/` or `storage/app/public/` (the second is
     * symlinked to the first by `storage:link`), and it must never be committed.
     *
     * The default puts it in `storage/app/firebase/`, which this application
     * never serves — every request on this host is routed through Laravel, so a
     * URL pointing there answers 404 rather than handing out the key.
     */
    'firebase' => [
        'credentials' => env(
            'FIREBASE_CREDENTIALS',
            storage_path('app/firebase/service-account.json'),
        ),
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

    'sms_misr' => [
        'url' => env('SMS_MISR_URL', 'https://smsmisr.com/api/SMS/'),
        'environment' => env('SMS_MISR_ENVIRONMENT', '2'), // 1 = live, 2 = test — confirm with SMS Misr's dashboard
        'username' => env('SMS_MISR_USERNAME'),
        'password' => env('SMS_MISR_PASSWORD'),
        'sender' => env('SMS_MISR_SENDER'),
    ],

];
