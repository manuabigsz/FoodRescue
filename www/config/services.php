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

    'solana' => [
        'cluster' => env('SOLANA_CLUSTER', 'devnet'),
        'rpc_url' => env('SOLANA_RPC_URL', 'https://api.devnet.solana.com'),
        'commitment' => env('SOLANA_COMMITMENT', 'confirmed'),
        'program_id' => env('SOLANA_PROGRAM_ID'),
        'token_mint' => env('SOLANA_TOKEN_MINT'),
        'protocol_authority' => env('SOLANA_PROTOCOL_AUTHORITY'),
        'protocol_treasury' => env('SOLANA_PROTOCOL_TREASURY'),
    ],

];
