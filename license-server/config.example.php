<?php

return [
    'base_path' => '/api/lisans',
    'database' => __DIR__ . '/data/licenses.sqlite',
    'private_key_file' => __DIR__ . '/data/private.key',
    'public_key_file' => __DIR__ . '/data/public.key',
    'admin_token' => 'replace-with-a-long-random-admin-token',
    'debug' => false,
    'cors' => ['*'],
    'rate_limit' => 120,
    'check_interval' => 3600,
    'grace_hours' => 72,
    'plans' => [
        'monthly' => [
            'name' => 'Aylik Ticari Lisans',
            'price_cents' => 10000,
            'currency' => 'TRY',
            'duration_days' => 30,
            'max_activations' => 1,
            'features' => ['ads', 'advanced_recommendations', 'short_video', 'premium', 'commercial_use'],
        ],
        'yearly' => [
            'name' => 'Yillik Ticari Lisans',
            'price_cents' => 100000,
            'currency' => 'TRY',
            'duration_days' => 365,
            'max_activations' => 2,
            'features' => ['ads', 'advanced_recommendations', 'short_video', 'premium', 'commercial_use'],
        ],
    ],
];
