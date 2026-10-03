<?php

declare(strict_types=1);

return [

    /*
     * ТЗ §79: CORS_ALLOWED_ORIGINS is an explicit list. Never "*" in production —
     * the embed widget is served to known partner domains only. `paths` covers
     * the public catalog and the embed endpoints; admin routes are same-origin.
     */
    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'Idempotency-Key', 'X-Request-Id', 'X-Locale'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
