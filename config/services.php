<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'stripe' => [
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'basic_price_id' => env('STRIPE_PRICE_BASIC'),
        'business_price_id' => env('STRIPE_PRICE_BUSINESS'),
        'premium_price_id' => env('STRIPE_PRICE_PREMIUM'),
        'seat_price_id' => env('STRIPE_PRICE_RECRUITER_SEAT'),
        'visa_price_id' => env('STRIPE_PRICE_VISA_PACKAGE'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'project' => env('OPENAI_PROJECT'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com'),
        'quality_model' => env('OPENAI_QUALITY_MODEL', 'gpt-5.6-terra'),
        'economy_model' => env('OPENAI_ECONOMY_MODEL', 'gpt-5.6-luna'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 90),
        'store' => (bool) env('OPENAI_STORE', false),
        'eu_data_controls' => (bool) env('OPENAI_EU_DATA_CONTROLS', false),
        'document_ai_enabled' => (bool) env('OPENAI_DOCUMENT_AI_ENABLED', false),
    ],

    'livekit' => [
        'url' => env('LIVEKIT_URL'),
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
        'token_ttl_minutes' => (int) env('LIVEKIT_TOKEN_TTL_MINUTES', 10),
        'e2ee_required' => (bool) env('LIVEKIT_E2EE_REQUIRED', true),
        'region' => env('LIVEKIT_REGION', 'eu'),
    ],

    'zammad' => [
        'enabled' => (bool) env('ZAMMAD_ENABLED', false),
        'url' => env('ZAMMAD_URL'),
        'token' => env('ZAMMAD_TOKEN'),
        'group' => env('ZAMMAD_GROUP', 'Users'),
        'webhook_secret' => env('ZAMMAD_WEBHOOK_SECRET'),
        'timeout' => (int) env('ZAMMAD_TIMEOUT', 10),
    ],

    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],

];
