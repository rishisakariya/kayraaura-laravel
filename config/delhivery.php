<?php

return [
    'enabled' => (bool) env('DELHIVERY_ENABLED', true),
    'env' => env('DELHIVERY_ENV', 'staging'),
    'mock' => (bool) env('DELHIVERY_MOCK', false),
    'token' => env('DELHIVERY_TOKEN'),
    'client_name' => env('DELHIVERY_CLIENT_NAME'),
    'pickup_location' => env('DELHIVERY_PICKUP_LOCATION'),
    'seller_gst_tin' => env('DELHIVERY_SELLER_GST_TIN'),
    'default_hsn_code' => env('DELHIVERY_DEFAULT_HSN_CODE'),
    'sync_cache_minutes' => (int) env('DELHIVERY_SYNC_CACHE_MINUTES', 15),

    'urls' => [
        'staging' => [
            'create' => 'https://staging-express.delhivery.com/api/cmu/create.json',
            'track' => 'https://staging-express.delhivery.com/api/v1/packages/json/',
            'cancel' => 'https://staging-express.delhivery.com/api/p/edit',
            'packing_slip' => 'https://staging-express.delhivery.com/api/p/packing_slip',
        ],
        'production' => [
            'create' => 'https://track.delhivery.com/api/cmu/create.json',
            'track' => 'https://track.delhivery.com/api/v1/packages/json/',
            'cancel' => 'https://track.delhivery.com/api/p/edit',
            'packing_slip' => 'https://track.delhivery.com/api/p/packing_slip',
        ],
    ],
];
