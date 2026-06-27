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
        'x_account_number' => env('RAZORPAYX_ACCOUNT_NUMBER'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'whatsapp' => [
            'base_url' => env('WHATSAPP_CLOUD_BASE_URL', 'https://graph.facebook.com'),
            'version' => env('WHATSAPP_CLOUD_API_VERSION', 'v25.0'),
            'access_token' => env('WHATSAPP_CLOUD_ACCESS_TOKEN'),
            'phone_number_id' => env('WHATSAPP_CLOUD_PHONE_NUMBER_ID'),
            'webhook_verify_token' => env('WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN'),
            'language' => env('WHATSAPP_CLOUD_TEMPLATE_LANGUAGE', 'en_US'),
            'country_code' => env('WHATSAPP_CLOUD_COUNTRY_CODE', '91'),
            'body_parameters' => array_values(array_filter(array_map(
                'trim',
                explode(',', env('WHATSAPP_CLOUD_BODY_PARAMETERS', 'otp'))
            ))),
            'button' => [
                'enabled' => env('WHATSAPP_CLOUD_OTP_BUTTON_ENABLED', true),
                'sub_type' => env('WHATSAPP_CLOUD_OTP_BUTTON_SUB_TYPE', 'url'),
                'index' => env('WHATSAPP_CLOUD_OTP_BUTTON_INDEX', '0'),
            ],
            'templates' => [
                'default' => env('WHATSAPP_CLOUD_DEFAULT_TEMPLATE'),
                'forgot_password' => env('WHATSAPP_CLOUD_FORGOT_PASSWORD_TEMPLATE'),
                'cod_order' => env('WHATSAPP_CLOUD_COD_ORDER_TEMPLATE'),
            ],
        ],
        'msg91' => [
            'endpoint' => env('MSG91_ENDPOINT', 'https://api.msg91.com/api/v5/flow/'),
            'auth_key' => env('MSG91_AUTH_KEY'),
            'flow_id' => env('MSG91_FLOW_ID'),
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
