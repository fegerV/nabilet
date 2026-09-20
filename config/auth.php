<?php

declare(strict_types=1);

return [

    /*
     * PROVISIONAL — deliberately minimal.
     *
     * The public API is bearer-token based (OpenAPI `bearerAuth`) and tokens are
     * backed by `user_sessions` / `api_keys`, not by a session cookie. The guard
     * driver for that is registered by the Auth module in phase P2; defining it
     * here would leave a dangling guard if the module were disabled.
     *
     * Until then only `web` is wired, which is enough for the installer and the
     * admin SPA. `Nabilet\Modules\Users\Models\User` is created in P2 — `::class`
     * resolves to a plain string here, so nothing is loaded at boot.
     */
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => \Nabilet\Modules\Users\Models\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
