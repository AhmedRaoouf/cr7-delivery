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

    /*
     * «المتابعة باستخدام Apple / Google» — `POST /auth/social`.
     *
     * `client_ids` is the **audience allow-list**: every `aud` this deployment will accept
     * on an id_token, comma-separated. There is normally more than one, because the same
     * person signing in from iOS and from Android presents two different audiences:
     *
     *   APPLE_CLIENT_IDS   the iOS bundle id (com.cr7delivery.cr7Delivery), plus the
     *                      Services ID if Apple sign-in is ever offered on the web.
     *   GOOGLE_CLIENT_IDS  the iOS OAuth client id AND the **Web** client id — the Android
     *                      SDK signs its id_token with the web client, not the Android one,
     *                      which is the single most common reason a working iOS build and a
     *                      broken Android build sit side by side.
     *
     * These are public identifiers, not secrets — they ship inside the app binary. They
     * are still not committed, because they differ per environment and an empty list is
     * the safe default: `IdentityTokenVerifier` refuses the whole endpoint with
     * `social_not_configured` rather than accepting an unchecked audience.
     */
    'apple' => [
        'client_ids' => env('APPLE_CLIENT_IDS', ''),
        'keys_url' => env('APPLE_KEYS_URL', 'https://appleid.apple.com/auth/keys'),
    ],

    'google' => [
        'client_ids' => env('GOOGLE_CLIENT_IDS', ''),
        'keys_url' => env('GOOGLE_KEYS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
    ],

    'sms_misr' => [
        'url' => env('SMS_MISR_URL', 'https://smsmisr.com/api/SMS/'),
        'environment' => env('SMS_MISR_ENVIRONMENT', '2'), // 1 = live, 2 = test — confirm with SMS Misr's dashboard
        'username' => env('SMS_MISR_USERNAME'),
        'password' => env('SMS_MISR_PASSWORD'),
        'sender' => env('SMS_MISR_SENDER'),
    ],

];
