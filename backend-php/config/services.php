<?php

return [
    'support' => [
        'phone' => env('SUPPORT_PHONE'),
        'email' => env('SUPPORT_EMAIL'),
    ],
    'weather' => [
        'webhook_secret' => env('WEATHER_WEBHOOK_SECRET'),
        'api_key' => env('WEATHER_API_KEY'),
    ],
    'pilot' => [
        'feedback_admin_ids' => env('PILOT_FEEDBACK_ADMIN_IDS', ''),
    ],
    'community' => [
        'admin_ids' => env('COMMUNITY_ADMIN_IDS', ''),
    ],
    'kyc' => [
        'admin_ids' => env('KYC_ADMIN_IDS', ''),
    ],
    'mpesa' => [
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'shortcode' => env('MPESA_SHORTCODE'),
        'passkey' => env('MPESA_PASSKEY'),
        'callback_url' => env('MPESA_CALLBACK_URL'),
    ],
    'monetization' => [
        'premium_monthly_minor' => (int) env('PREMIUM_MONTHLY_MINOR', 50000),
        'ad_campaign_minor' => (int) env('AD_CAMPAIGN_MONTHLY_MINOR', 0),
        'ad_admin_ids' => env('AD_ADMIN_IDS', ''),
    ],
];
