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

    'sms_misr' => [
        'url' => env('SMS_MISR_URL', 'https://smsmisr.com/api/SMS/'),
        'environment' => env('SMS_MISR_ENVIRONMENT', '2'), // 1 = live, 2 = test — confirm with SMS Misr's dashboard
        'username' => env('SMS_MISR_USERNAME'),
        'password' => env('SMS_MISR_PASSWORD'),
        'sender' => env('SMS_MISR_SENDER'),
    ],

];
