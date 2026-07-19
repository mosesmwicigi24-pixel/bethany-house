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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    'whatsapp' => [
        'token'               => env('WABA_TOKEN'),
        'phone_number_id'     => env('WABA_PHONE_NUMBER_ID'),
        // WhatsApp Business Account id — required only to create/manage message
        // templates (WhatsApp Manager → Account tools → shows the ID). Sending
        // works without it. See `php artisan whatsapp:otp-template`.
        'business_account_id' => env('WABA_ID'),
        'api_version'         => env('WABA_API_VERSION', 'v19.0'),
        // Approved AUTHENTICATION template used for passwordless order lookup.
        'otp_template'        => env('WABA_OTP_TEMPLATE', 'order_lookup_code'),
        'otp_template_lang'   => env('WABA_OTP_TEMPLATE_LANG', 'en'),
    ],

];
