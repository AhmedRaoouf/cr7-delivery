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
     * Batch 68 — the two dashboards reach this API from their own Node server (the BFF),
     * so every place owner and every operator arrives from **one** address and would
     * share one per-address SMS allowance: a single stranger on the dashboard's «نسيت
     * كلمة المرور» could spend it for all of them. With this key set here and in both
     * dashboards, a request carrying `X-CR7-Bff-Key: <key>` has its `X-CR7-Client-Ip`
     * believed for the OTP and sign-in limits, and nobody else's is. At least 32
     * characters, compared in constant time; empty turns the header off. See
     * {@see \App\Support\OtpRequester}.
     */
    'bff' => [
        'key' => env('CR7_BFF_KEY'),
    ],

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
     * SMS Misr — the OTP gateway. See App\Services\Otp\SmsMisrChannel for the
     * two traps: it answers HTTP 200 on failure, and `language` is an encoding
     * rather than a locale.
     */
    'sms_misr' => [
        'url' => env('SMS_MISR_URL', 'https://smsmisr.com/api/SMS/'),
        'otp_url' => env('SMS_MISR_OTP_URL', 'https://smsmisr.com/api/OTP/'),

        // Read-only, costs nothing and needs no approved Sender ID, which makes
        // it the only way to prove a username and password before there is
        // anything to send with. `cr7:sms-doctor` calls it.
        'balance_url' => env('SMS_MISR_BALANCE_URL', 'https://smsmisr.com/api/Balance/'),

        // 'sms' posts our own text to /api/SMS/. 'otp' posts an SMS-Misr-hosted
        // template id to /api/OTP/ and needs `template` below. Ask SMS Misr which
        // one the account is provisioned for — an account set up for templated
        // OTP traffic refuses free-text sends.
        'mode' => env('SMS_MISR_MODE', 'sms'),
        'template' => env('SMS_MISR_TEMPLATE'),

        // 1 = live (real message, real points), 2 = test (validated, not delivered,
        // paid from the separate test balance). Stays 2 until a Sender ID is approved.
        'environment' => env('SMS_MISR_ENVIRONMENT') ?: env('SMSMISR_ENVIRONMENT') ?: '2',

        // NOT a locale — an encoding. 1 = English/GSM-7, which cannot carry an
        // Arabic letter; 2 = Arabic; 3 = Unicode. Try 3 if the Arabic arrives as
        // question marks, because the copy below mixes Arabic with a Latin brand.
        'language' => env('SMS_MISR_LANGUAGE', '2'),

        // SMS Misr's own console hands these out as `SMSMISR_USERNAME` and so on —
        // no underscore between SMS and MISR. Pasted verbatim under our spelling
        // alone, every one of them read as null and the gateway answered 1902
        // with nothing in the log to say why. Ours wins; theirs is accepted.
        //
        // `?:`, not env()'s default argument. A line reading `SMS_MISR_USERNAME=`
        // is an empty STRING to Laravel, not an absent key, so the default never
        // fires — and the production .env has carried exactly those three empty
        // lines since the first deploy. Appending SMS Misr's block beneath them
        // would have changed nothing.
        'username' => env('SMS_MISR_USERNAME') ?: env('SMSMISR_USERNAME'),
        'password' => env('SMS_MISR_PASSWORD') ?: env('SMSMISR_PASSWORD'),
        'sender' => env('SMS_MISR_SENDER') ?: env('SMSMISR_SENDER'),

        // `:code` is the only placeholder. Arabic bills at 70 characters per
        // segment against English's 160, so keep it to one segment.
        'message' => env('SMS_MISR_MESSAGE', 'كود التحقق الخاص بك في CR7 Delivery هو: :code'),
    ],

    /*
     * ZADX — the OTP gateway (smsportal.zadx.net). See App\Services\Otp\ZadxChannel.
     * Key and secret are the pair the ZADX dashboard shows on the app's page
     * (`pk_…` / `sk_…`). `?:`, not env()'s default argument: an empty `.env` line is
     * an empty string, and the default would never fire (same trap as SMS Misr's).
     */
    'zadx' => [
        'base_url' => env('ZADX_BASE_URL') ?: 'https://smsapi.zadx.net/api/v1',
        'key' => env('ZADX_API_KEY') ?: null,
        'secret' => env('ZADX_API_SECRET') ?: null,

        // Must be assigned to the app. Empty lets ZADX use the app's default sender.
        'sender' => env('ZADX_SENDER_ID') ?: 'ZADX',

        // `ar` or `en` — the language of ZADX's own OTP template.
        'locale' => env('ZADX_LOCALE') ?: 'ar',

        // Seconds. The send is queued on ZADX's side and answers 202, so this is generous.
        'timeout' => (int) (env('ZADX_TIMEOUT') ?: 15),
    ],

    /*
     * Store-review accounts (Google Play "App access", App Store review). The numbers
     * listed here — and only these — are sent one fixed six-digit code instead of an
     * SMS. See App\Support\ReviewAccess. Both empty (the default) means the feature is
     * off; a code that is not six digits also switches it off.
     */
    'review' => [
        'phones' => env('OTP_REVIEW_PHONES') ?: null,
        'code' => env('OTP_REVIEW_CODE') ?: null,
    ],

];
