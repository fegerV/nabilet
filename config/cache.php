<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
     * Redis is optional (ТЗ §3): CACHE_STORE=redis is the recommended setting,
     * but `database` must stay a working fallback so a host without Redis runs.
     */
    'default' => env('CACHE_STORE', 'database'),

    'stores' => [

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => null,
            'lock_connection' => null,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

    ],

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'nabilet'), '_') . '_cache_'),

];
