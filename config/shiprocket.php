<?php

$environment = env('SHIPROCKET_ENV', 'staging') === 'production' ? 'production' : 'staging';

return [
    // staging | production (Shiprocket uses the same API host for both; credentials differ)
    'env' => $environment,

    'enabled' => (bool) env('SHIPROCKET_ENABLED', false),

    // When true, no real Shiprocket HTTP calls are made (recommended for local/staging tests).
    'mock' => (bool) env('SHIPROCKET_MOCK', false),

    'base_url' => $environment === 'production'
        ? env('SHIPROCKET_PRODUCTION_BASE_URL', env('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in'))
        : env('SHIPROCKET_STAGING_BASE_URL', env('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in')),

    'credentials' => [
        'email' => $environment === 'production'
            ? env('SHIPROCKET_PRODUCTION_EMAIL', env('SHIPROCKET_EMAIL'))
            : env('SHIPROCKET_STAGING_EMAIL', env('SHIPROCKET_EMAIL')),
        'password' => $environment === 'production'
            ? env('SHIPROCKET_PRODUCTION_PASSWORD', env('SHIPROCKET_PASSWORD'))
            : env('SHIPROCKET_STAGING_PASSWORD', env('SHIPROCKET_PASSWORD')),
    ],

    'pickup_location' => $environment === 'production'
        ? env('SHIPROCKET_PRODUCTION_PICKUP_LOCATION', env('SHIPROCKET_PICKUP_LOCATION'))
        : env('SHIPROCKET_STAGING_PICKUP_LOCATION', env('SHIPROCKET_PICKUP_LOCATION')),

    'channel_id' => $environment === 'production'
        ? env('SHIPROCKET_PRODUCTION_CHANNEL_ID', env('SHIPROCKET_CHANNEL_ID'))
        : env('SHIPROCKET_STAGING_CHANNEL_ID', env('SHIPROCKET_CHANNEL_ID')),

    'courier_id' => $environment === 'production'
        ? env('SHIPROCKET_PRODUCTION_COURIER_ID', env('SHIPROCKET_COURIER_ID'))
        : env('SHIPROCKET_STAGING_COURIER_ID', env('SHIPROCKET_COURIER_ID')),

    'token_cache_minutes' => (int) env('SHIPROCKET_TOKEN_CACHE_MINUTES', 1439),
    'sync_cache_minutes' => (int) env('SHIPROCKET_SYNC_CACHE_MINUTES', 15),

    'seller' => [
        'name' => env('SHIPROCKET_SELLER_NAME', 'Seller'),
        'address_line_1' => env('SHIPROCKET_SELLER_ADDRESS_LINE_1'),
        'address_line_2' => env('SHIPROCKET_SELLER_ADDRESS_LINE_2'),
        'landmark' => env('SHIPROCKET_SELLER_LANDMARK'),
        'city' => env('SHIPROCKET_SELLER_CITY'),
        'state' => env('SHIPROCKET_SELLER_STATE'),
        'postal_code' => env('SHIPROCKET_SELLER_POSTAL_CODE'),
        'country' => env('SHIPROCKET_SELLER_COUNTRY', 'India'),
        'phone' => env('SHIPROCKET_SELLER_PHONE'),
        'email' => env('SHIPROCKET_SELLER_EMAIL'),
    ],
];
