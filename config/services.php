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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'auth_max_age' => (int) env('TELEGRAM_AUTH_MAX_AGE', 86400),
        'admin_ids' => array_values(array_filter(array_map('trim', explode(',', env('TELEGRAM_ADMIN_IDS', ''))))),
    ],

    'bakong' => [
        'api_url' => env('BAKONG_API_URL', 'https://api-bakong.nbc.gov.kh'),
        'token' => env('BAKONG_TOKEN'),
        'account_type' => env('BAKONG_ACCOUNT_TYPE', 'individual'),
        'account_id' => env('BAKONG_ACCOUNT_ID'),
        'merchant_name' => env('BAKONG_MERCHANT_NAME'),
        'merchant_city' => env('BAKONG_MERCHANT_CITY', 'Phnom Penh'),
        'merchant_id' => env('BAKONG_MERCHANT_ID'),
        'acquiring_bank' => env('BAKONG_ACQUIRING_BANK'),
        'store_label' => env('BAKONG_STORE_LABEL'),
        'terminal_label' => env('BAKONG_TERMINAL_LABEL'),
        'mcc' => env('BAKONG_MCC', '5999'),
        'shop_currency' => env('SHOP_CURRENCY', 'USD'),
        'currency' => env('BAKONG_CURRENCY', 'USD'),
        'usd_to_khr_rate' => (float) env('BAKONG_USD_TO_KHR_RATE', 4026),
        'qr_expiry_minutes' => (int) env('BAKONG_QR_EXPIRY_MINUTES', 15),
    ],

];
