<?php

declare(strict_types=1);

return [

    /*
     * Redis is optional (ТЗ §3): QUEUE_CONNECTION=redis when available,
     * `database` otherwise. The database driver needs the `jobs` table, which
     * is part of the standard Laravel baseline migration, not the domain schema.
     */
    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [

        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => (int) env('NABILET_HOLD_TTL', 600) + 60,
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('NABILET_HOLD_TTL', 600) + 60,
            'block_for' => null,
            'after_commit' => false,
        ],

        'sync' => [
            'driver' => 'sync',
        ],

    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
