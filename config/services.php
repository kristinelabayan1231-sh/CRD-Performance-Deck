<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'shecom' => [
        'url' => env('SHECOM_API_URL', 'https://gm-shecom.up.railway.app/api'),
        'key' => env('SHECOM_API_KEY'),
    ],

    'pancake' => [
        'pos_url' => env('PANCAKE_API_BASE_URL_ONE', 'https://pos.pages.fm/api/v1'),
        'chat_url' => env('PANCAKE_CHAT_API_URL', 'https://pages.fm/api/public_api/v1'),
        'key' => env('PANCAKE_API_KEY'),
        // A POS user access token; used instead of the API key when set.
        'access_token' => env('PANCAKE_ACCESS_TOKEN'),
        'shop_id' => env('PANCAKE_SHOP_ID'),
        // Facebook pages for chat engagements live in Settings → Pancake Pages (pancake_pages table).
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
