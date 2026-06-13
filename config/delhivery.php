<?php

return [
    'env' => env('DELHIVERY_ENV', 'staging'),
    'mock' => (bool) env('DELHIVERY_MOCK', false),
    'token' => env('DELHIVERY_TOKEN'),
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
