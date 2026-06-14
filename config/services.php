<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'razorpay' => [
        'key' => env('RAZORPAY_KEY_ID'),
        'secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'msg91' => [
            'endpoint' => env('MSG91_ENDPOINT', 'https://api.msg91.com/api/v5/flow/'),
            'auth_key' => env('MSG91_AUTH_KEY'),
            'flow_id' => env('MSG91_FLOW_ID'),
            'forgot_password_flow_id' => env('MSG91_FORGOT_PASSWORD_FLOW_ID'),
            'cod_order_flow_id' => env('MSG91_COD_ORDER_FLOW_ID'),
            'sender_id' => env('MSG91_SENDER_ID'),
            'country_code' => env('MSG91_COUNTRY_CODE', '91'),
            'variables' => [
                'otp' => env('MSG91_OTP_VARIABLE', 'OTP'),
                'purpose' => env('MSG91_PURPOSE_VARIABLE', 'PURPOSE'),
                'expiry' => env('MSG91_EXPIRY_VARIABLE', 'EXPIRY_MINUTES'),
            ],
        ],
    ],

];
