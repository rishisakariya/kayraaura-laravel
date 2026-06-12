<?php

return [
    'env' => env('DELHIVERY_ENV', 'staging'),
    'token' => env('DELHIVERY_TOKEN'),
    'client_name' => env('DELHIVERY_CLIENT_NAME'),
    'pickup_location' => env('DELHIVERY_PICKUP_LOCATION'),
    'seller_gst_tin' => env('DELHIVERY_SELLER_GST_TIN'),
    'default_hsn_code' => env('DELHIVERY_DEFAULT_HSN_CODE'),
    'default_length_cm' => (int) env('DELHIVERY_DEFAULT_LENGTH_CM', 10),
    'default_width_cm' => (int) env('DELHIVERY_DEFAULT_WIDTH_CM', 10),
    'default_height_cm' => (int) env('DELHIVERY_DEFAULT_HEIGHT_CM', 5),
    'webhook_secret' => env('DELHIVERY_WEBHOOK_SECRET'),
    'sync_cache_minutes' => (int) env('DELHIVERY_SYNC_CACHE_MINUTES', 15),

    'urls' => [
        'staging' => [
            'create' => 'https://staging-express.delhivery.com/api/cmu/create.json',
            'track' => 'https://staging-express.delhivery.com/api/v1/packages/json/',
            'cancel' => 'https://staging-express.delhivery.com/api/p/edit',
        ],
        'production' => [
            'create' => 'https://track.delhivery.com/api/cmu/create.json',
            'track' => 'https://track.delhivery.com/api/v1/packages/json/',
            'cancel' => 'https://track.delhivery.com/api/p/edit',
        ],
    ],
];
