<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        'https://yourdomain.com',
        'https://www.yourdomain.com',
        // Add your Next.js frontend domain
        env('FRONTEND_URL', 'http://localhost:3000'),
        // Customer-facing storefront (bethanyhouse.co.ke) — a separate
        // origin from the hub frontend; needed for the guest checkout
        // bridge and the live order-status endpoint.
        env('STOREFRONT_URL'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-API-Key', // For API key auth
    ],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
    ],

    'max_age' => 0,

    'supports_credentials' => true,
];