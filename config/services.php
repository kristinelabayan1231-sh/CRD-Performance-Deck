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

    // Call recordings: the Drive is connected in Settings → Call Recordings with the Google client above.
    'google_drive' => [
        'folder_name' => env('GOOGLE_DRIVE_FOLDER_NAME', 'CRD Call Recordings'),
    ],

    'shecom' => [
        'url' => env('SHECOM_API_URL', 'https://gm-shecom.up.railway.app/api'),
        'key' => env('SHECOM_API_KEY'),
        // A separate key for the sales endpoint (per-order sales without the child TSD row).
        'sales_key' => env('SHECOM_SALES_API_KEY'),
        // GM Logistics v2's external API: product costs for the Daily I.S.
        'external_url' => env('SHECOM_EXTERNAL_API_URL', 'https://gm-logistics-v2-production.up.railway.app/api/external/v1'),
        'product_cost_key' => env('SHECOM_PRODUCT_COST_API_KEY'),
        // Memory for reading the retention report (about 150 MB decoded).
        'memory_limit' => env('SHECOM_MEMORY_LIMIT', '512M'),
    ],

    'pancake' => [
        'pos_url' => env('PANCAKE_API_BASE_URL_ONE', 'https://pos.pages.fm/api/v1'),
        'chat_url' => env('PANCAKE_CHAT_API_URL', 'https://pages.fm/api/public_api/v1'),
        // Pancake's inbox, where a lead's chat opens: {inbox_url}/{page_id}?c_id={conversation_id}.
        'inbox_url' => env('PANCAKE_INBOX_URL', 'https://pancake.ph'),
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
