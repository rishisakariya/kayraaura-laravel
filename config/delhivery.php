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
    'default_length_cm' => (int) env('DELHIVERY_DEFAULT_LENGTH_CM', 10),
    'default_width_cm' => (int) env('DELHIVERY_DEFAULT_WIDTH_CM', 10),
    'default_height_cm' => (int) env('DELHIVERY_DEFAULT_HEIGHT_CM', 5),
    'sync_cache_minutes' => (int) env('DELHIVERY_SYNC_CACHE_MINUTES', 15),
    'webhook_secret' => env('DELHIVERY_WEBHOOK_SECRET'),
    'label_pdf_size' => env('DELHIVERY_LABEL_PDF_SIZE', '4R'),
    'auto_schedule_pickup' => (bool) env('DELHIVERY_AUTO_SCHEDULE_PICKUP', true),
    'pickup_batch_delay_seconds' => (int) env('DELHIVERY_PICKUP_BATCH_DELAY_SECONDS', 180),
    'pickup_time' => env('DELHIVERY_PICKUP_TIME', '14:00:00'),
    'pickup_same_day_cutoff' => env('DELHIVERY_PICKUP_SAME_DAY_CUTOFF', '14:00'),

    'urls' => [
        'staging' => [
            'create' => 'https://staging-express.delhivery.com/api/cmu/create.json',
            'track' => 'https://staging-express.delhivery.com/api/v1/packages/json/',
            'cancel' => 'https://staging-express.delhivery.com/api/p/edit',
            'packing_slip' => 'https://staging-express.delhivery.com/api/p/packing_slip',
            'pickup_request' => 'https://staging-express.delhivery.com/fm/request/new/',
        ],
        'production' => [
            'create' => 'https://track.delhivery.com/api/cmu/create.json',
            'track' => 'https://track.delhivery.com/api/v1/packages/json/',
            'cancel' => 'https://track.delhivery.com/api/p/edit',
            'packing_slip' => 'https://track.delhivery.com/api/p/packing_slip',
            'pickup_request' => 'https://track.delhivery.com/fm/request/new/',
        ],
    ],
];
