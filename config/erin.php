<?php

return [
    'bootstrap_admin' => [
        'email' => env('ERIN_BOOTSTRAP_ADMIN_EMAIL'),
        'name' => env('ERIN_BOOTSTRAP_ADMIN_NAME', 'Erin Superadmin'),
    ],
    'health' => [
        'search_required' => (bool) env('ERIN_HEALTH_SEARCH_REQUIRED', true),
        'clamav_required' => (bool) env('ERIN_HEALTH_CLAMAV_REQUIRED', true),
        'metrics_token' => env('ERIN_METRICS_TOKEN'),
    ],
];
