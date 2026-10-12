<?php

declare(strict_types=1);

return [

    /*
     * Two surfaces, two guards, one `users` table, ONE model.
     *
     *   web  cookie session — the installer and the Vue admin SPA. The SPA's
     *        access gate is `canAccessAdmin()`, which the model itself answers.
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
     * BOTH PROVIDERS POINT AT THE SAME MODEL. They used to point at two classes
     * for one table (`App\Models\User` and the module one), which was recorded
     * as an open decision in `docs/REVIEW-spec-bundle.md` §3.25. It is closed:
     * `Nabilet\Modules\Core\Users\Models\User` owns the domain, and
     * `App\Models\User` is now a subclass of it that declares nothing of its
     * own — so a guard hands out one class either way and behaviour cannot drift
     * (see `tests/Feature/Core/UserModelUnificationTest.php`).
     */

    'defaults' => [
        'guard' => 'web',
        // 'passwords' намеренно не назван: брокер паролей не используется
        // (см. пояснение ниже). Оставлять ссылку на несуществующего провайдера
        // — значит обещать путь, которого нет.
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
            'model' => \Nabilet\Modules\Core\Users\Models\User::class,
        ],

        'api_users' => [
            'driver' => 'eloquent',
            'model' => \Nabilet\Modules\Core\Users\Models\User::class,
        ],
    ],

    /*
     * НЕТ блока `passwords` — и это решение, не забывчивость.
     *
     * Он называл таблицу `password_reset_tokens`, которой НЕТ в схеме (сверено с
     * живой базой: 0 колонок) и которую ТЗ не определяет. Ссылка висела в
     * конфиге, рассылая будущего читателя искать поток, который её использует.
     * Его не было: `AccountTokenService` выдаёт подписанный stateless-токен
     * (Crypt, payload = public id + e-mail + purpose + срок + отпечаток хеша
     * пароля), и `/auth/password/forgot` + `/auth/password/reset` работают без
     * таблицы. Stateless здесь сильнее: токен умирает сам по сроку и сам
     * аннулируется сменой пароля (отпечаток в payload), а таблицу пришлось бы
     * ещё и чистить.
     *
     * Стандартный Laravel-брокер паролей (`Password::broker()`) не вызывается
     * ни одним маршрутом, сервисом или тестом — проверено поиском. Поэтому
     * провайдер не зарегистрирован, и `expire: 60` не потерян: срок живёт в
     * `AccountTokenService::DEFAULT_TTL`, откуда его и читает поток.
     *
     * Если однажды появится требование хранить токены сброса (отзыв конкретной
     * ссылки, аудит запросов), правильный ход — НОВАЯ таблица в пакете плюс
     * замечание в §3.25, а не возврат этой ссылки: она указывала на несуществующее.
     */

    'password_timeout' => 10800,

];
