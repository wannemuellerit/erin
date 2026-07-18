<?php

use NotificationChannels\WebPush\PushSubscription;

return [
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => PushSubscription::class,

    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    'database_connection' => env(
        'WEBPUSH_DB_CONNECTION',
        env('DB_CONNECTION', 'mysql'),
    ),

    'client_options' => [
        'timeout' => (int) env('WEBPUSH_TIMEOUT', 15),
        'allow_redirects' => false,
    ],

    'allowed_endpoint_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'WEBPUSH_ALLOWED_ENDPOINT_HOSTS',
            'fcm.googleapis.com,android.googleapis.com,updates.push.services.mozilla.com,push.services.mozilla.com,web.push.apple.com,notify.windows.com',
        )),
    ))),

    'automatic_padding' => (bool) env('WEBPUSH_AUTOMATIC_PADDING', true),
];
