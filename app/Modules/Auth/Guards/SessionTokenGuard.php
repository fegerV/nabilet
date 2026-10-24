<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Guards;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Nabilet\Modules\Auth\Services\SessionIssuer;

/**
 * The bearer guard for the public API (ТЗ §5, §6).
 *
 * WHY THIS EXISTS INSTEAD OF SANCTUM
 *   `personal_access_tokens` appears nowhere in the ТЗ. The ТЗ defines
 *   `user_sessions` — `session_token_hash CHAR(64) NOT NULL` with
 *   `UNIQUE uq_user_sessions_token` — and `config/auth.php` documents a bearer
 *   guard backed by `user_sessions`/`api_keys` as the intent.
 *
 *   This guard is the only one that works: `auth:sanctum` was a wiring mistake
 *   and is no longer used anywhere. Sanctum registers its own `sanctum` guard
 *   with `provider => null`, so it can only resolve a token out of
 *   `personal_access_tokens` — a table nothing in this application ever writes.
 *   Every route that carried `auth:sanctum` therefore answered 401 to a
 *   perfectly valid `user_sessions` token. All of them now use `auth:api`.
 *
 * WHY NOT EXTEND `TokenGuard`
 *   `TokenGuard` asks the provider for `retrieveByCredentials(['api_token' => …])`,
 *   which builds `where api_token = ?`. The credential is a *hash* in a different
 *   table with an expiry, so the lookup cannot be expressed as a provider
 *   credential query at all. `TokenGuard::validate()` also returns false
 *   unconditionally in Laravel 12+. So this implements `Guard` directly and lets
 *   `SessionIssuer` own the lookup.
 *
 * `GuardHelpers` supplies `check()`, `guest()`, `id()`, `authenticate()`,
 * `hasUser()`, `setUser()` and the provider accessors; `user()` and `validate()`
 * are the two the trait does not define, and they are the two that carry the
 * token logic.
 *
 * THE REQUEST ARRIVES LATE, ON PURPOSE
 *   The guard is built once per request by the auth manager, which caches it, so
 *   the request cannot be a constructor argument — it would bind whichever request
 *   happened to be current when the guard was first resolved. `setRequest()` is
 *   called through the container's `refresh()`, which is the same mechanism
 *   Laravel uses for `SessionGuard`.
 */
final class SessionTokenGuard implements Guard
{
    use GuardHelpers;

    private ?Request $request = null;

    /** Whether a token lookup has already been attempted for this request. */
    private bool $attempted = false;

    /**
     * Получен ли `$user` разбором bearer-токена (в отличие от явного `setUser()`).
     *
     * Различие нужно ровно для одного: `setRequest()` обесценивает токен-производное
     * состояние, но не трогает пользователя, поставленного вызывающим.
     */
    private bool $resolvedFromToken = false;

    public function __construct(
        UserProvider $provider,
        private readonly SessionIssuer $issuer,
    ) {
        $this->provider = $provider;
    }

    /**
     * Привязать guard к запросу — и обесценить то, что выведено из ТОКЕНА.
     *
     * ЧТО ОБЕСЦЕНИВАЕТСЯ И ЧТО НЕТ. Сбрасываются только `attempted` и `user`,
     * если пользователь был ПОЛУЧЕН РАЗБОРОМ токена. Пользователь, поставленный
     * явно (`setUser()`, а через него `actingAs()` в тестах и `Auth::login()` в
     * приложении), сбросом не затрагивается: это решение вызывающего, а не кеш,
     * и стирать его значило бы отменить `actingAs()` на первом же запросе.
     *
     * ПОЧЕМУ СБРОС ВООБЩЕ НУЖЕН. Guard кешируется менеджером аутентификации
     * (`AuthManager::$guards`), а сам кеширует результат в `$user` и `$attempted`
     * — намеренно, чтобы `check()`, `guest()` и `id()` не делали по запросу к
     * базе. В PHP-FPM это безопасно: процесс умирает вместе с запросом. В
     * ДОЛГОЖИВУЮЩЕМ воркере (Octane) — нет: тот же экземпляр guard'а обслуживает
     * следующий запрос, и без сброса второй запрос с ДРУГИМ Bearer-токеном
     * получил бы пользователя первого. То есть чужую личность: `/me`,
     * `/me/orders` и `/my-tickets` вернули бы данные не того человека.
     *
     * Почему это всплыло только сейчас: сброса не было с самого начала, но и
     * проверить его было нечем — каждый тест жил в одном запросе. Как только
     * появился тест, делающий ДВА запроса подряд (смена пароля, затем проверка,
     * что старый токен мёртв), стало видно, что второй запрос отвечает `200` по
     * сессии, которой уже нет.
     *
     * `refresh('request', …)` в `AuthServiceProvider` вызывает этот метод именно
     * при подмене `request`, поэтому сброс происходит ровно тогда, когда нужен.
     */
    public function setRequest(Request $request): static
    {
        // Тот же самый объект — значит, привязку переставили в пределах ОДНОГО
        // запроса. Так делает `Kernel::dispatchToRouter()`: он ещё раз кладёт
        // `request` в контейнер перед маршрутными middleware, и это дёргает
        // rebinding-callback. Сбрасывать кеш здесь нельзя — иначе глобальный
        // `ResolveOrganizationContext` разбирает токен, а `auth:api` сразу за ним
        // разбирает его ВТОРОЙ раз: лишние `SELECT user_sessions`,
        // `SELECT users` и лишний `UPDATE last_seen_at` на каждом запросе.
        if ($this->request === $request) {
            return $this;
        }

        $this->request = $request;

        if ($this->resolvedFromToken) {
            $this->user = null;
            $this->resolvedFromToken = false;
        }

        $this->attempted = false;

        return $this;
    }

    /**
     * Пользователь поставлен ЯВНО — это не кеш токена, и сброс его не касается.
     *
     * Переопределение нужно, чтобы снять `resolvedFromToken`: иначе явно
     * поставленный пользователь, пришедший на место разобранного из токена,
     * считался бы токен-производным и был бы стёрт на следующем запросе.
     */
    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        $this->resolvedFromToken = false;

        return $this;
    }

    /** Забыть пользователя — вместе с признаком «получен из токена». */
    public function forgetUser(): static
    {
        $this->user = null;
        $this->resolvedFromToken = false;

        return $this;
    }

    /**
     * The user behind the bearer token, or null.
     *
     * The result — including a negative one — is remembered, because `check()`,
     * `guest()` and `id()` all call this and a request that asks twice should not
     * query twice.
     */
    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        if ($this->attempted) {
            return null;
        }

        $this->attempted = true;

        $token = $this->bearerToken();

        if ($token === null) {
            return null;
        }

        $session = $this->issuer->authenticate($token);

        if ($session === null) {
            return null;
        }

        $user = $this->provider->retrieveById($session->user_id);

        // A soft-deleted account keeps its rows, and `users` has a `deleted_at`
        // column while the model does not use `SoftDeletes` — so `retrieveById()`
        // will happily return it. Refusing here fails closed, the same choice made
        // for an unbounded session and an unrecognised cart status.
        //
        // `status` is deliberately NOT checked here: the ТЗ does not say which
        // values deny access to an ALREADY-ISSUED session, so gating on one would
        // be inventing policy. Recorded in docs/REVIEW-spec-bundle.md.
        //
        // Уточнение обоснования: комментарий здесь говорил «VARCHAR(32) with no
        // CHECK constraint» — это было верно до `ck_users_status` (сплит 013).
        // С тех пор схема допускает ровно `active`, `inactive`, `banned`, и
        // вход при не-`active` действительно закрыт — но ДРУГИМ местом:
        // `UserStatus::mayAuthenticate()` в `UserService::authenticate()`.
        // Проверка при входе и проверка на каждом запросе — разные решения:
        // отзыв сессий при бане уже сделан (`UserSessionRevocationTest`), и
        // повторная проверка статуса в guard'е его не заменяет.
        if ($user === null || self::isDeleted($user)) {
            return null;
        }

        $this->resolvedFromToken = true;

        return $this->user = $user;
    }

    /**
     * Validate a raw token.
     *
     * `Guard` requires this. The credential is the token itself, not an
     * identifier/password pair, so the only key that means anything is `token`.
     */
    public function validate(array $credentials = []): bool
    {
        $token = $credentials['token'] ?? null;

        if (! is_string($token) || $token === '') {
            return false;
        }

        return $this->issuer->authenticate($token) !== null;
    }

    /**
     * The token from `Authorization: Bearer …`, or null.
     *
     * The scheme is matched case-insensitively because RFC 7235 defines it that
     * way, and a client sending `bearer` is not making a mistake.
     */
    public function bearerToken(): ?string
    {
        $header = $this->request?->header('Authorization');

        if (! is_string($header)) {
            return null;
        }

        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private static function isDeleted(Authenticatable $user): bool
    {
        return $user instanceof Model && $user->getAttribute('deleted_at') !== null;
    }
}
