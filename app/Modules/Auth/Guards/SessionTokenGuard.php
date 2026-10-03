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
 *   guard backed by `user_sessions`/`api_keys` as the intent. `auth:sanctum` on
 *   these routes was a wiring mistake, not a missing dependency.
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

    public function __construct(
        UserProvider $provider,
        private readonly SessionIssuer $issuer,
    ) {
        $this->provider = $provider;
    }

    public function setRequest(Request $request): static
    {
        $this->request = $request;

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
        // `status` is deliberately NOT checked: it is `VARCHAR(32)` with no CHECK
        // constraint and the ТЗ does not say which values deny access, so gating on
        // one would be inventing policy. Recorded in docs/REVIEW-spec-bundle.md.
        if ($user === null || self::isDeleted($user)) {
            return null;
        }

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
