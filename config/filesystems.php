<?php

declare(strict_types=1);

return [

    /*
     * Local disk is for a single-host install. Anything multi-host must move to
     * the `s3` disk: uploaded media and offline bundles have to be readable by
     * every app node, and local storage silently breaks behind a load balancer.
     */
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * S3-compatible: AWS S3, Yandex Object Storage, MinIO (ТЗ §3).
         * AWS_ENDPOINT + AWS_USE_PATH_STYLE_ENDPOINT cover MinIO/Yandex.
         */
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'ru-central1'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [
        // Для шаред-хостинга (Timeweb) где public переименован в public_html
        // Если используется стандартная структура - оставить public_path('storage')
        // Путь будет автоматически адаптирован при установке через installer
        public_path('storage') => storage_path('app/public'),
    ],

];
