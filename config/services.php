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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'opensky' => [
        'base_url' => env('OPENSKY_BASE_URL', 'https://opensky-network.org/api'),
        'token_url' => env('OPENSKY_TOKEN_URL', 'https://auth.opensky-network.org/auth/realms/opensky-network/protocol/openid-connect/token'),
        'client_id' => env('OPENSKY_CLIENT_ID'),
        'client_secret' => env('OPENSKY_CLIENT_SECRET'),
        'timeout' => (int) env('OPENSKY_TIMEOUT', 30),
    ],

];
