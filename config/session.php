<?php

declare(strict_types=1);

return [

    'driver' => env('SESSION_DRIVER', 'database'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => 'sessions',

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    /*
     * The API is bearer-token based, so cookies matter only for the admin SPA
     * and the installer. `same_site=lax` plus `secure` in production keeps the
     * installer flow from leaking a session over plain HTTP.
     */
    'cookie' => env('SESSION_COOKIE', 'nabilet_session'),

    'path' => '/',

    'domain' => env('SESSION_DOMAIN'),

    'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),

    'http_only' => true,

    'same_site' => 'lax',

    'partitioned' => false,

];
