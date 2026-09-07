<?php

return [
    'wallet_challenge_ttl_minutes' => (int) env('WALLET_CHALLENGE_TTL_MINUTES', 5),
    'requests_per_minute_per_ip' => 180,
    'token_lifetime_minutes' => (int) env('API_TOKEN_LIFETIME_MINUTES', 480),
    'blockchain_reconciliation_grace_minutes' => (int) env('BLOCKCHAIN_RECONCILIATION_GRACE_MINUTES', 30),
    'max_tokens_per_user' => 5,
    'initial_admin' => [
        'name' => env('INITIAL_ADMIN_NAME'),
        'email' => env('INITIAL_ADMIN_EMAIL'),
        'password' => env('INITIAL_ADMIN_PASSWORD'),
    ],
];
