<?php

declare(strict_types=1);

return [

    /*
     * Two surfaces, two guards, one `users` table.
     *
     *   web  cookie session — the installer and the Vue admin SPA. The SPA
     *        resolves users through `App\Models\User` (its access gate is
     *        `canAccessAdmin()`), so that provider is left alone.
     *
     *   api  bearer token — the public REST API (OpenAPI `bearerAuth`). Tokens are
     *        NOT session cookies: they are rows in `user_sessions`, matched by
     *        `session_token_hash` (CHAR(64), the sha256 of the token). The ТЗ
     *        defines that table and never mentions `personal_access_tokens`, so
     *        Sanctum is not the mechanism here.
     *
     * The `session_token` driver named below is registered by the Auth module
     * (`Nabilet\Modules\Auth\Providers\AuthServiceProvider`) — config can name a
     * driver, it cannot implement one. Disabling that module therefore leaves this
     * guard dangling, which is inherent: five modules route through `auth:api`.
     *
     * The two providers point at two different model classes for the same table.
     * That duplication is pre-existing and recorded as an open decision in
     * docs/REVIEW-spec-bundle.md — `App\Models\User` is the web guard's
     * model, `Nabilet\Modules\Core\Users\Models\User` is the module's. Unifying
     * them is a decision about which one owns the domain, not a cleanup.
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

        'api' => [
            'driver' => 'session_token',
            'provider' => 'api_users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => \App\Models\User::class,
        ],

        'api_users' => [
            'driver' => 'eloquent',
            'model' => \Nabilet\Modules\Core\Users\Models\User::class,
        ],
    ],

    /*
     * `password_reset_tokens` does NOT exist in the schema — verified against the
     * live database, where the table had zero columns. The ТЗ defines no
     * password-reset table either.
     *
     * The `users` entry below is therefore NOT the storage the reset flow uses:
     * nothing reads this table. `Nabilet\Modules\Auth\Services\AccountTokenService`
     * issues a signed, stateless reset token (Crypt, payload = user public id +
     * e-mail + purpose + expiry + password-hash fingerprint) and the flow never
     * touches a table. This block is kept only so the `passwords` key resolves for
     * anything that asks for it by name; `expire: 60` is superseded by
     * `AccountTokenService::DEFAULT_TTL`.
     */
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
