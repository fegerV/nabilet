<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'NABILET'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    'timezone' => env('APP_TIMEZONE', 'Europe/Moscow'),

    /*
     * Locales the public catalog is published in (ТЗ §39). `fallback_locale` is
     * the single default; `locales` is what the CMS and the /{locale}/... router
     * accept. Kept in config so modules ask for the list instead of parsing .env.
     */
    'locale' => env('APP_FALLBACK_LOCALE', 'ru'),

    'locales' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('APP_LOCALES', 'ru,en')),
    ))),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'ru'),

    'faker_locale' => 'ru_RU',

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'maintenance' => [
        'driver' => 'file',
    ],
];
