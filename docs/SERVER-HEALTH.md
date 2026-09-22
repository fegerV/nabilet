# NABILET Core — server health check

**Date:** 2026-09-22 · **Scope:** the running instance on this machine, not a code review.
**Method:** live HTTP probes, `artisan`, and direct queries against the connected database.
Every number below was measured, not inferred.

Reproduce everything here with:

```bash
PHP=C:/Users/Professional/AppData/Local/VertexCMS/tools/php/php.exe
$PHP tools/verify-live.php            # live DB + route table vs spec
$PHP tools/verify-error-envelope.php  # §66 envelope ratchet
$PHP artisan route:list --path=api/v1
$PHP artisan migrate:status
```

---

## 1. Verdict

The server **boots, connects, and serves traffic**. Two of the P0s below were found and **fixed and
verified live**: the database enforcing none of the spec's invariants (§3), and the error contract
being violated on every framework-owned path (§7). A third — the API being unable to authenticate
anyone — is now **fixed and verified live** as well (§4.4).

What remains is **not** what was carried in from the previous session. The API can now authenticate,
but **four `UserService` methods still do not exist**, so the auth endpoints that need them still fail
(§4) — and, discovered while fixing the guard, **no module service provider has ever booted**, which
makes `config/nabilet.php`'s provider list dead config and leaves `role:admin` pointing at an alias
nobody registers (§4.4).

| # | Finding | Severity | Evidence |
|---|---------|----------|----------|
| 1 | **The authentication module is a facade** — `AuthController` calls 4 `UserService` methods that do not exist, plus 2 Sanctum methods on a model without the trait. | **P0** (half closed) | `Call to undefined method …UserService::registerCustomer()`. The guard half is **fixed and verified** — see §4.4; the four service methods are still missing |
| 2 | ~~`auth:sanctum` guarded 7 routes but **no such guard is defined**~~ **FIXED** — replaced by an `api` guard backed by `user_sessions`, as the spec intends | ~~P0~~ **closed** | was `Auth guard [sanctum] is not defined.` → now **401** on all four protected route groups, and a live token authenticates: `tools/verify-auth-live.php` 28/28 |
| 3 | ~~The database enforces **none** of the spec's CHECK constraints, and the immutability trigger is absent — while `migrate:status` reports every migration as `Ran`~~ **FIXED** — see §3 | ~~P0~~ **closed** | was `check 0/35`, `trigger 0/1` → now `check 35/35`, `trigger 1/1`, both proven to reject bad writes |
| 4 | ~~Every error body on every framework-owned path was outside the §66 envelope, and `APP_DEBUG=true` published stack traces with absolute paths~~ **FIXED** — see §7 | ~~P0~~ **closed** | 34 call sites across 8 files; live bodies now `{"error":{"code":…,"request_id":…}}` |
| 5 | `APP_ENV=production` with **`APP_DEBUG=true`** — still leaks traces on **HTML** surfaces (`/admin`, the installer) | **P0** (deployment) | API paths are now redacted by §7; the `.env` flag still needs to be `false` |
| 6 | **67 of the spec's 81 API paths are not served** | **P0** | `tools/verify-live.php` §2 |
| 7 | **No module service provider ever boots** — `Nabilet\Core\NabiletServiceProvider` is referenced **nowhere**, so `config/nabilet.php`'s nine providers are dead config, the kernel's "load-bearing" singletons are not singletons, and `role:admin` names an alias nobody registers — so every Halls management route **500s for an authenticated caller** | **P0** | `getLoadedProviders()` returned **0** `Modules\` entries (§4.4); `tools/repro-role-alias.php` → 401 anonymous / **500** authenticated |

---

## 2. What works

| Check | Result |
|-------|--------|
| PHP dev server on `127.0.0.1:8000` | listening (PHP 8.4.21) |
| `GET /up` | 200 |
| `GET /api/v1/ping` | 200 |
| `GET /admin/login` | 200 — Filament panel renders |
| `GET /admin` | 302 → login (correct) |
| Security headers | `X-Request-ID`, CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` all present — `AssignRequestId` and `ApplySecurityHeaders` are live |
| Database connection | reachable; 13 migrations ran |
| Live schema — tables | 64 spec tables present (65 incl. Laravel's `migrations`) |
| Live schema — columns | 678 spec columns all present; **1 extra** (`users.remember_token`) |
| Live schema — foreign keys | 93 live / 93 spec |
| Live schema — unique constraints | 81 live / 81 spec |
| Live schema — CHECK constraints | 35 live / 35 spec — and proven to reject violating writes (§3) |
| Live schema — immutability trigger | 1 live / 1 spec — and proven to reject published-geometry edits (§3) |
| `GET /api/v1/venues/1/halls` | 200, paginated JSON — the Halls read path works end-to-end against the live DB |
| Error contract | `tools/verify-error-envelope.php` — 412 files, 0 violations, 4 reasoned exceptions (§7) |
| Full lint | 521 files, 0 syntax errors |
| Test suite | 639 tests, 0 failed, 1324 assertions |
| **API authentication** | `tools/verify-auth-live.php` — **28 checks, 0 failed**: 401 (not 500) on every protected route, a live token authenticates, and tampered / expired / unbounded / revoked / soft-deleted are all refused |
| **Not working — measured, not inferred** | `POST /api/v1/halls` returns **500** to an *authenticated* caller (`Target class [role] does not exist`); anonymous callers get 401 first. `tools/repro-role-alias.php`, §9 |
| All other verifiers | purity 101 files/0 violations · openapi 11/11 (98 operations) · contract-schema 28/0 drift · migrations 13/0 · models-schema no new drift (387 fields/67 models) · autoload · module-structure 33 dirs · state-machines · `modules.php --validate` · verify-live — all PASS |

---

## 3. P0 — the database did not enforce the spec · **FIXED AND VERIFIED**

**The migrations were MySQL-only; the server runs PostgreSQL.**

`database/migrations/2026_09_20_001000_add_check_constraints.php` guards all 35
`ALTER TABLE ... ADD CONSTRAINT ... CHECK` statements behind:

```php
return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
```

and `…_001100_add_schema_version_immutability_trigger.php` does the same for the one
trigger. `.env` says `DB_CONNECTION=pgsql`, so both migrations take the early-return path.

**A migration that returns early still counts as run.** `migrate:status` showed all 13 as
`Ran`, and `tools/verify-migrations.php` passed 13/13 — because that verifier parses the
migration *files*, not the database. Two independent green signals over a database that
enforced nothing:

```
check    live    0   spec   35   MISMATCH
trigger  live    0   spec    1   MISMATCH
```

What was therefore unenforced: every status enum (`ck_orders_status`,
`ck_payments_status`, `ck_tickets_status`, `ck_sessions_status`, `ck_schema_status`),
non-negative money on orders/payments/refunds/promo codes, `ck_inventory_target`
(a seat row must have a seat and no standing zone, or the reverse), and
`ck_tickets_terminal_exclusive`.

Two things make this worse than a plain omission:

- **The spec targets MySQL, and the deployment does not.** `nabilet_core_spec/migrations.sql`
  uses `DELIMITER //` and `SIGNAL SQLSTATE '45000'` — MySQL dialect. So this is not a bug in
  the migrations; it is a deployment that does not match the product.
- **001000 skipped silently**, contradicting its own class docblock ("on any other driver they
  are skipped loudly, not silently"). Nothing in the output hinted that 35 invariants had
  just been dropped. (001100 *did* warn on stderr; 001000 did not.)

### What was done

Every expression in the file is standard SQL, so the contract was no longer withheld on
PostgreSQL — it was made portable, without changing MySQL behaviour:

- **001000** — `supportsCheckConstraints()` now accepts `pgsql` as well. The loud skip is kept
  for drivers that genuinely cannot add a CHECK to an existing table (SQLite needs a table
  rebuild), and the silent early return was replaced with the same stderr warning 001100 uses.
- **001100** — the trigger is now implemented for both dialects: MySQL keeps
  `SIGNAL SQLSTATE '45000'` with `<=>`, PostgreSQL gets a `PL/pgSQL` function using
  `RAISE ... ERRCODE '45000'` and `IS DISTINCT FROM` (the null-safe counterpart of `<=>`). Same
  six columns compared, same message.

Applied to the live database and verified:

```
check    live   35   spec   35   ok
trigger  live    1   spec    1   ok
```

### Proof that they are enforced, not merely present

A row in `pg_constraint` shows a constraint exists; it does not show the database refuses a bad
write. So the minimum valid parent chain
(`organizations → venues → halls → hall_schema_versions`) was built inside a transaction and then
deliberately broken — **with a control for every attempt**, because a rejection only means
something if the same statement succeeds when the value is legal:

| Attempt | Result |
|---------|--------|
| `INSERT` a schema version with `status='bogus'` | **rejected by `ck_schema_status`** |
| control: the same insert with `status='published'` | accepted — so the rejection was the constraint, not the row shape |
| `UPDATE` the geometry of the published version | **rejected: immutability exception raised** |
| control: the same edit while `status='draft'` | allowed — so the trigger blocks only published/archived, as designed |

The transaction was rolled back and the database left as found (`org_probe` absent; only the
pre-existing "NABILET Demo" organisation remains).

**For whoever deploys next:** these migrations are recorded as already run on *this* database, so
they were applied here by invoking them directly. A fresh `php artisan migrate` on a new database
applies them normally.

---

## 4. P0 — authentication: the module was a facade, and the guard is now built

**This section corrects the conclusion reached earlier in the same session.** The previous finding
was "`laravel/sanctum` is not installed". That is true but it is not the main problem, and
installing Sanctum would be the wrong fix.

### 4.1 What actually happens

All six auth endpoints fail. Measured live, one call each:

| Endpoint | Live result |
|----------|-------------|
| `POST /auth/register` | **500** — `Call to undefined method Nabilet\Modules\Core\Users\Services\UserService::registerCustomer()` |
| `POST /auth/login` (bad credentials) | 401 `{"error":"Invalid credentials"}` — a flat string, not the envelope |
| `POST /auth/login` (valid credentials) | would reach `$user->createToken()` → **500**, see 4.2 |
| `POST /auth/logout` | **500** — `Auth guard [sanctum] is not defined.` |
| `POST /auth/verify-email` | **500** — `Auth guard [sanctum] is not defined.` |
| `POST /auth/forgot-password` | **500** — `Call to undefined method …UserService::sendPasswordResetLink()` |
| `POST /auth/reset-password` | **500** — `Call to undefined method …UserService::resetPassword()` |

`UserService` declares ten methods. `AuthController` calls five of them:

| Called by `AuthController` | Exists? |
|---------------------------|---------|
| `authenticate()` | yes |
| `registerCustomer()` | **no** |
| `sendPasswordResetLink()` | **no** |
| `resetPassword()` | **no** |
| `verifyEmail()` | **no** |

So the controller, its `FormRequest`s and its `AuthResource` all exist, and the service layer they
call was never written. Nothing caught this because the only tests that drive these classes need
`vendor/` (they are the three `tests/Feature/Api/*` files that have never been executed here), and
`lint.php` reads only `php -l`'s exit code — an undefined method is a runtime error, not a syntax
error.

### 4.2 The token mechanism is also absent, and it is not Sanctum

`AuthController` also calls `$user->createToken('customer-token')->plainTextToken` (register and
login) and `auth()->user()?->currentAccessToken()->delete()` (logout). Both are Sanctum APIs.
`laravel/sanctum`:

- in `composer.json` `require`: **no**
- in `composer.lock`: **0 occurrences**
- in `vendor/laravel/`: **absent**

`app/Modules/Core/Users/Models/User` does **not** use `HasApiTokens`, so even with the package
installed, `createToken()` would be an undefined method. And
`app/Modules/Auth/Providers/AuthServiceProvider.php` carries
`use Laravel\Sanctum\Sanctum;` — an import of a class that does not exist on disk. It is only
referenced from a comment, so it is currently inert; it is a trap for the next edit.

### 4.3 Sanctum is the wrong mechanism, not merely a missing one

This is the part that changes the recommendation. The spec does not describe Sanctum's schema at
all:

- `personal_access_tokens` appears **nowhere** in `nabilet_core_spec/` (verified by grep across the
  whole bundle).
- The spec defines its own session table:

  ```sql
  CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    session_token_hash CHAR(64) NOT NULL,
    …
    UNIQUE KEY uq_user_sessions_token (session_token_hash),
  ```

- `config/auth.php` documents the intent in its own header comment: *"The public API is
  bearer-token based (OpenAPI `bearerAuth`) and tokens are backed by `user_sessions` / `api_keys`,
  not by a session cookie. The guard driver for that is registered by the Auth module in phase P2;
  defining it here would leave a dangling guard if the module were disabled."*
- The building blocks for that already exist and are unused: `app/Modules/Core/Models/UserSession.php`,
  `app/Modules/Auth/Domain/UserSession.php`, `app/Modules/Auth/Domain/SessionPolicy.php`,
  `app/Modules/Auth/Domain/SessionDecision.php`.

**Therefore `auth:sanctum` is a wiring bug, not a missing dependency.** Installing Sanctum would add
a table the spec does not define, and would leave `user_sessions` — which the spec does define, with
a `CHAR(64)` token hash and a unique index — permanently unused. The correct fix is a bearer guard
backed by `user_sessions`, which is what the config comment says was always intended.

**The guard is now built — see §4.4.** What remains of this section is the service layer: four
`UserService` methods and the controller rewrite that uses them.

Affected today: Users, Organizations, Payments (index/show), the protected half of Halls, and
`/auth/logout` + `/auth/verify-email`.

### 4.4 The guard is built, verified live, and it exposed a second P0

**Fixed and verified.** The API now authenticates through `user_sessions`, which is what the spec
describes, and every protected route answers **401** instead of 500.

| Piece | File |
|-------|------|
| Token + hash | `app/Modules/Auth/Domain/SessionToken.php` — 64 random bytes; `sha256` hex is exactly the `CHAR(64)` the column declares; framework-free, so it is unit-tested |
| Issue / honour / revoke | `app/Modules/Auth/Services/SessionIssuer.php` — the only writer of `user_sessions`; asks `Domain\SessionPolicy` for every decision |
| Client context | `app/Modules/Auth/Services/ClientContext.php` — IP, user agent, device name, truncated to the declared column widths |
| The guard | `app/Modules/Auth/Guards/SessionTokenGuard.php` — a real `Illuminate\Contracts\Auth\Guard` |
| Driver | `AuthServiceProvider::registerSessionTokenDriver()` — `Auth::extend('session_token', …)` |
| The guard entry | `config/auth.php` — `'api' => ['driver' => 'session_token', 'provider' => 'api_users']` |
| Routes | 7 `auth:sanctum` occurrences in 5 files → `auth:api` |

`SessionTokenGuard` deliberately does **not** extend `TokenGuard`: that class asks the provider for
`retrieveByCredentials(['api_token' => …])`, which builds `where api_token = ?`, but the credential is
a **hash in a different table with an expiry**, so the lookup cannot be expressed as a provider
credential query at all. `TokenGuard::validate()` also returns false unconditionally in Laravel 12+.
So the guard implements `Guard` directly and lets `SessionIssuer` own the lookup.

`tools/verify-auth-live.php` proves it: **28 checks, 0 failed**. It asserts 401-not-500 on four
protected routes, then every decision the session rules describe — unknown, tampered, expired,
`expires_at IS NULL` (fails closed), revoked, and a soft-deleted account — and the storage invariants:
the token is not stored in plaintext, and `ip_address` holds 4 bytes for `127.0.0.1`.

#### Two traps found while building it, both invisible to every other check

**1. `Container::refresh()` does not call the method it is given.** It *registers a rebinding
callback* that fires the next time `request` is rebound. The provider originally called only
`$app->refresh('request', $guard, 'setRequest')` — which reads exactly like Laravel's own idiom — so
the guard kept a **null request** and refused **every** token. Every refusal test still passed,
because "no token" and "bad token" are indistinguishable from outside. Only the *positive* case caught
it. The fix pushes the current request in immediately and keeps the rebinding for long-running
workers; `tests/Unit/AuthWiringTest` now asserts both calls exist.

**2. A packed IP silently truncates when bound as a string.** `ip_address` is `VARBINARY(16)` per the
spec, which is `bytea` on PostgreSQL. Binding `inet_pton('127.0.0.1')` as an ordinary string stored
**one byte** (`7f`) instead of four — no error, no warning — and a `10.x.x.x` address is almost all
zero bytes, so it stored one byte too. Reproduced in `tools/repro-binary-ip.php`, which also shows the
forms that do work: `PDO::PARAM_LOB` and `decode('<hex>', 'hex')` are exact. `Nabilet\Core\Support\
PackedIp` emits the latter, and `UNHEX()` for MySQL — the spec's actual target.

#### The P0 this uncovered: no module service provider boots

The driver had to be registered somewhere that actually runs, and establishing that produced the more
serious finding. **`Nabilet\Core\NabiletServiceProvider` is referenced nowhere.** It is the kernel's
documented single entry point — "Register each module's own service provider", in its own docblock —
and nothing registers it. Measured:

```
getLoadedProviders()  →  53 providers, of which 0 are under Modules\
config/nabilet.php    →  names 9 module providers, none of them registered
```

The routes still work, which is why this stayed invisible: `routes/api.php` **hardcodes a `require` of
13 module route files**, so the endpoints exist while the providers that own them never boot.
Consequences beyond auth:

- `HookRegistry` and `OrganizationContext` are documented as singletons that "must be the SAME
  instance for the whole request". They are not — `$this->app->singleton()` never runs — so every
  `make()` returns a new object, which is precisely the bug their comment warns about.
- `role:admin` on the hall management routes names an alias **nobody registers**. The comment in
  `bootstrap/app.php` says modules register their own aliases "from their service providers", and
  those providers do not run.
- The nine providers in `config/nabilet.php` are dead config.

**Only the Auth provider was registered** (`bootstrap/providers.php`) — the minimum that makes the API
authenticate. Registering the whole registry is the real fix and is deliberately **not** attempted
here: every module provider calls `loadRoutesFrom()`, which is a bare `require` with no prefix, so
booting them all would register every module's endpoints a second time at an unversioned path. That
refactor needs its own change, and it is now the top item in §9.

---

## 5. P0 (deployment) — production with debug on

`.env`: `APP_ENV=production`, `APP_DEBUG=true`. `.env` is gitignored, so this is a deployment
setting on this machine, not something the repository can fix.

**Partially mitigated by §7.** Before that fix, every unhandled error returned a traced JSON body
with absolute filesystem paths (`C:\Project\nabilet\...`), class names and line numbers — for
example `GET /api/v1/halls/xyz` returned `{"message":"Hall not found","exception":"NotFoundHttpException","trace":[…]}`.

`ApiExceptionRenderer` now intercepts **every** throwable on `api/*` / `expectsJson` requests and
answers with the envelope, so no trace reaches an API caller regardless of `APP_DEBUG`. Verified:
the same request that previously returned a full trace now returns
`{"error":{"code":"INTERNAL_ERROR","message":"Something went wrong. Please try again.","request_id":"…"}}`.

**What is still exposed:** HTML surfaces — `/admin`, the Filament panel, the installer — keep
Laravel's own rendering (deliberately: a JSON envelope in a browser would be a regression), so
`APP_DEBUG=true` still publishes traces there. Set `APP_DEBUG=false` before this instance is
reachable by anyone.

---

## 6. P0 — the API does not serve the spec

`nabilet_core_spec/openapi.yaml` declares **81 paths**. The app registers **53** API routes.
**14** spec paths are served as specified; **67** are not.

Unbuilt namespaces, by count:

| Namespace | Missing paths | Notes |
|-----------|---------------|-------|
| `/api/v1/admin/*` | 24 | the entire admin API surface |
| `/api/v1/embed/*` | 8 | public embed endpoints |
| `/api/v1/me/*` | 5 | self-service |
| `/api/v1/checkin/*` + `/checkin-devices/*` + `/offline-bundles/*` | 5 | the Android checker API |
| `/api/v1/carts/*` | 4 | app models a *single* session cart (`/cart`), the spec models a cart resource — see §8 |
| `/api/v1/promo-codes/*` | 3 | |
| `/api/v1/media/*` | 2 | |
| translations (`/venues/…`, `/pages/…`) | 4 | |
| auth password/email paths | 3 | app has `/auth/forgot-password`; spec has `/auth/password/forgot` |
| OAuth callbacks, telegram, health, webhooks/yookassa, others | 5 | |

Some of these are genuine missing features; some are naming mismatches (cart vs carts,
`forgot-password` vs `password/forgot`). They need to be separated before the roadmap is
re-costed — see `docs/REVIEW-spec-bundle.md`.

---

## 7. P0 — the §66 envelope was violated on every framework-owned path · **FIXED AND VERIFIED**

### 7.1 The finding

`AppError` and its eight subclasses existed, `bootstrap/app.php` rendered them correctly, and the
contract was documented in three places. But **no module controller ever threw one**, and Laravel's
own exceptions were never mapped — so the envelope was honoured only on the handful of paths the
domain owned, and violated everywhere else. Measured live, before the fix:

| Request | Body returned | Why it is wrong |
|---------|---------------|-----------------|
| `POST /api/v1/cart/items` `{}` | `{"message":"validation.required","errors":{…}}` | no `error` object, no `code`, no `request_id` — and the message is a raw translation key |
| `GET /api/v1/cart` | `{"error":"session_id required"}` | `error` is a **string**, not an object |
| `GET /api/v1/halls/xyz` | `{"message":"Hall not found","exception":"NotFoundHttpException","trace":[…]}` | not the envelope, and leaks a stack trace |
| `GET /api/v1/events/999999` | `{"message":"No query results for model [Nabilet\Modules\Events\Models\Event] 999999"}` | leaks the internal namespace layout |
| `GET /api/v1/organizations/x` | 500 with a full trace | `auth:sanctum` — see §4 |

The static gates could not see any of this. They parse files; these bodies are produced by Laravel
at runtime. And the suite stayed green, because the three tests that drive the framework have never
been executed in this environment.

### 7.2 The fix

One mapper, `app/Core/Http/ApiExceptionRenderer.php`, wired into the exception handler in
`bootstrap/app.php`. It maps the framework's exceptions onto the same envelope the domain uses:

| Framework exception | Rendered as |
|--------------------|-------------|
| `ValidationException` | 422 `VALIDATION_ERROR`, field messages under `details.fields` |
| `AuthenticationException` | 401 `UNAUTHENTICATED` |
| `AuthorizationException` / `AccessDeniedHttpException` | 403 `FORBIDDEN` |
| `ModelNotFoundException` (wrapped by Laravel) | 404 `<RESOURCE>_NOT_FOUND`, built from the bare class name |
| `NotFoundHttpException` | 404 `NOT_FOUND` |
| `MethodNotAllowedHttpException` | 405 `METHOD_NOT_ALLOWED` |
| `ThrottleRequestsException` | 429 `TOO_MANY_REQUESTS` |
| any other `HttpExceptionInterface` | its status, `HTTP_<status>` |
| anything else | 500 `INTERNAL_ERROR`, real message replaced, `report()`ed |

Two rules it enforces, both verified live:

- **A model's fully-qualified class name never reaches a client.** `Event` → `EVENT_NOT_FOUND` /
  `"Event not found."`. Laravel's default 404 embeds the namespace; that discloses the internal
  module layout.
- **An unexpected throwable is a bug, so its message is replaced.** `operational: false` errors are
  logged and answered with a generic 500. This is what closes the `APP_DEBUG` leak on API paths.

Alongside it, 34 hand-rolled responses across 8 files were replaced with `AppError` subclasses —
including 15 `abort(404, …)` calls, which render in Laravel's shape, not ours:

| File | Sites |
|------|-------|
| `Core/Organizations/Http/Controllers/OrganizationController.php` | 10 (3 flat + 7 `abort`) |
| `Core/Users/Http/Controllers/UserController.php` | 7 (`['message' => …]` with a 4xx — no `error` key at all) |
| `Modules/Cart/Http/Controllers/CartController.php` | 6 |
| `Venues/Halls/Http/Controllers/HallController.php` | 5 `abort` |
| `Core/Organizations/Http/Middleware/CheckOrganizationAccess.php` | 3 `abort` |
| `Modules/Tickets/Http/Controllers/CheckinController.php` | 1 |
| `Core/Http/Middleware/RateLimiter.php` | 1 — `retry_after` was beside `code`, not under `details` |
| `Core/Http/Middleware/CsrfProtection.php` | 1 |

`CartService` now raises `ConflictError`/`DomainRuleViolation` instead of `\RuntimeException`, so
the client gets `CART_EXPIRED`, `SEAT_UNAVAILABLE`, `CART_EMPTY` rather than a message to parse.
Statuses follow the spec: `POST /api/v1/carts/{cart}/items` declares **409** for an inventory
conflict, and the code previously answered 422.

### 7.3 Validation messages were raw keys

There was **no `lang/` directory at all**, so `__('validation.required')` returned the key itself.
`config/app.php` sets `locale` from `APP_FALLBACK_LOCALE=ru`, so a client saw
`{"message":"validation.required"}`.

Fixed by publishing Laravel's language files and adding the `ru` set (the product's default locale):
`lang/ru/{validation,auth,passwords,pagination}.php`, with an `attributes` map so the message reads
«Поле «Сессия» обязательно для заполнения» rather than naming the raw column.

### 7.4 A non-numeric id turned a 422 into a 500

`POST /api/v1/cart/items` with `{"session_id":"nope"}` returned **500**, not 422. Cause, proven by
reproducing it in isolation:

```
Illuminate\Database\QueryException: SQLSTATE[22P02]: Invalid text representation:
неверный синтаксис для типа bigint: "nope"
```

`sessions.id`, `inventory_items.id`, `tickets.id`, `users.id`, `venues.id` and `roles.id` are all
BIGINT, so `exists:sessions,id` issues `where id = 'nope'` and PostgreSQL rejects the cast. **Any
client could turn a validation error into a 500 by sending a string.** Fixed at 6 sites by adding
`bail` + `integer` before `exists`. `bail` is the load-bearing part, not `integer`: Laravel only
stops evaluating an attribute's remaining rules when `bail` is present, so `integer` failing is not
enough on its own. `UserController` already had `integer` and was still vulnerable for exactly that
reason.

### 7.5 A new ratchet, with a mutation test

`tools/verify-error-envelope.php` — 407 files, 0 violations, 4 reasoned exceptions. Six checks:

| Check | Catches |
|-------|---------|
| E1 | `'error' => '<string>'` |
| E2 | `'error' => $x->getMessage()` |
| E3 | `'error' => […]` with a key outside `{code, message, details, request_id}` |
| E4 | a 4xx/5xx `response()->json(…)` with no `error` key |
| E5 | `abort(…)` |
| E6 | `exists/unique:…,id` without a `bail` + `integer` guard |

Two properties worth stating, because a green check is worthless without them:

- **It strips comments with PHP's own lexer** (`token_get_all`), preserving byte offsets. Without
  that, this file and the classes that *document* these anti-patterns would trip their own check —
  the docblocks in `ApiExceptionRenderer`, `HallController` and `OrganizationController` all quote
  the bad shapes verbatim.
- **`--selftest` mutation-tests every check** against a violating snippet *and* a compliant
  near-miss, and asserts both directions. All six pass; a check that cannot go red proves nothing.
  It also asserts that docblocks are not scanned, since that regression would be silent.

Ratchet entries must stay live: an entry whose violation has been fixed is reported as **stale** and
fails the gate, so an exception cannot quietly license a future re-introduction of the same defect.

### 7.6 A lesson: the purity guard was right and I was wrong

`ApiExceptionRenderer` was first written into `app/Core/Errors/` — next to `AppError`, which looked
like the natural home. `tools/verify-purity.php` failed immediately with **12 violations**:
`app/Core/Errors` is guarded as framework-free, because `AppError` and its subclasses are the
vocabulary the *domain* speaks and a domain class must load with no vendor at all.

This class is the opposite of that — it exists only to know about Illuminate and Symfony exception
types. It is an HTTP-layer adapter, so it moved to `app/Core/Http/` where the Laravel-aware HTTP
layer lives. Purity is back to 99 files / 0 violations.

The follow-on lesson is in the test: `ErrorEnvelopeWiringTest` asserted the exact import string, so
the move broke it. The assertion is now namespace-agnostic (`preg_match` on the import), because
pinning a location in a test turns a correct refactor into a failure.

---

## 8. Defects found and fixed

The two largest fixes are documented separately: the database enforcing none of the spec's
invariants (§3) and the error contract being violated everywhere (§7).

| # | Defect | Fix |
|---|--------|-----|
| 1 | **53 routes carried a second version prefix** — `/api/v1/v1/events`, `/api/v1/api/v1/halls`. The global `apiPrefix` in `bootstrap/app.php` already mounts every module file at `/api/v1`, and 11 module route files repeated the segment. Only 3 of 81 spec paths were reachable. | Removed the duplicate segment from all 11 files. Spec paths served went **3 → 14**; zero doubled routes remain. |
| 2 | **`HallController` reached into a protected property.** `$this->service->repository->…` in 7 places; `HallService::$repository` is `protected` → `Cannot access protected property`, so *any* hall read returned 500. | Added `findByVenue` / `findByPublicId` / `getSchemaVersions` to `HallService`; controller now calls those. |
| 3 | **The same protected-property defect in `OrganizationController`** — `$this->service->repository->all()` / `->findByPublicId()`. Latent behind the `auth:sanctum` 500, so it had never surfaced. | Added `all()` / `findByPublicId()` accessors to `OrganizationService`. |
| 4 | **`HallRepository::findByVenue` declared `Collection` but returned a paginator** → `TypeError` on the one endpoint that worked. | Corrected the return type to `LengthAwarePaginator` (the `paginate()` call is intended — `HallCollection` is a `ResourceCollection`). |
| 5 | **`POST /api/v1/tickets/checkin/verify` returned 500 on every call.** The signature was `verify(Ticket $ticket, …)` but the route has no `{ticket}` segment, so Laravel tried to construct an empty `Ticket`. | The ticket now arrives in the body as `ticket_id`, matching `scan()`. |
| 6 | **`verify-models-schema.php` reported a false positive.** It read only a file's own text for `$table`, so four thin alias models that inherit `$table` from a sibling (`Halls\Models\Hall extends Venues\Models\Hall`) were reported as "no `$table`" and the gate failed on correct code. | The verifier now follows `extends` (resolving `use … as …` aliases) up to 4 levels. Mutation-tested in both directions: a bogus parent `$table` is reported against *both* the parent and the alias; an unresolvable parent still fails as "no `$table`". 67 models now checked, gate green. |
| 7 | **`verify-migrations.php` crashed (exit 255)** on `2026_09_22_001300_add_remember_token.php`: the Laravel stub had no `rememberToken()`, so the whole gate aborted and every later migration went unchecked. | Added `rememberToken()` to `tools/laravel-stub.php`. Gate now 13/13. |
| 8 | **`.gitignore` had lost its protections again** (4th occurrence). `Мысли о SEO.txt` — an internal document — was untracked and **not** ignored, one `git add -A` away from publication. No `*.pem` / `*.key` / `id_rsa*` / `.ssh/` patterns either. | Restored the private-document and key-material patterns, plus `!.env.example`. Verified: the document is now ignored, `.env.example` and `composer.lock` remain tracked (they are already in the index), CRLF preserved (135 CRLF / 0 bare LF). No key material exists on disk, so nothing was exposed. |
| 9 | **The 35 CHECK constraints and the immutability trigger existed in migration files only.** Both migrations were guarded to `mysql|mariadb`, so on PostgreSQL they returned early — and still counted as `Ran`. | Made portable: `pgsql` added to both guards; the trigger reimplemented in `PL/pgSQL` (`IS DISTINCT FROM`, `RAISE … ERRCODE '45000'`) alongside the MySQL version. Applied to the live DB and proven to reject violating writes, with controls. Details and proof in §3. |
| 10 | **The §66 envelope was violated on every framework-owned path**, and 34 hand-rolled error responses bypassed `AppError` entirely. | `ApiExceptionRenderer` maps framework exceptions onto the envelope; 34 call sites replaced. Details in §7. |
| 11 | **No `lang/` directory** → every validation message was a raw key (`validation.required`). | Published the framework's files and added the `ru` set with a field-label map. |
| 12 | **A non-numeric id produced 500 instead of 422** (`SQLSTATE[22P02]`, BIGINT cast). | `bail` + `integer` added before `exists` at 6 sites. |
| 13 | **No module service provider was ever registered.** `Nabilet\Core\NabiletServiceProvider` — the class whose docblock says it registers each module's provider — is referenced nowhere, and `getLoadedProviders()` returned **0** entries under `Modules\`. `config/nabilet.php` names 9 module providers; none was loaded. Nothing failed loudly, because `routes/api.php` mounts the module route files itself with hardcoded `require`s. | Registered `Nabilet\Modules\Auth\Providers\AuthServiceProvider` — the one provider the auth driver needs. Registering the rest is deliberately deferred: `ServiceProvider::loadRoutesFrom()` is a bare `require` with no prefix, so booting every provider would mount each module's routes a *second* time at an unprefixed path. It must land together with dropping the hardcoded `require`s. See §9. |
| 14 | **Every protected route answered 500, not 401** — `Auth driver [session_token] for guard [api] is not defined.`, a direct consequence of #13. This is why swapping `auth:sanctum` for `auth:api` changed the symptom instead of fixing it. | The `user_sessions` bearer guard is built and registered (§4.4). All four protected route groups now answer **401** in the §66 envelope, and a live token authenticates end-to-end. `tools/verify-auth-live.php`: 28 checks, 0 failed. |
| 15 | **`inet_pton()` written into a `VARBINARY(16)` / `bytea` column stored 1 byte instead of 4.** `127.0.0.1` became `7f`. Silent — no error, no truncation warning — and it hits only the private IPv4 ranges, because an IPv6 address has a non-zero first byte. | `Nabilet\Core\Support\PackedIp::toSqlLiteral()` emits `decode('<hex>','hex')` on PostgreSQL and `UNHEX('<hex>')` elsewhere, wrapped in an `Expression`. Proven by `tools/repro-binary-ip.php` (`len=1 hex=7f` for the bug, `len=4 hex=7f000001` for the fix) and by `length(ip_address) = 4` in the live verifier. 15 unit tests. |
| 16 | **`Container::refresh()` does not call the method it is handed.** It registers a rebinding callback for the *next* time the abstract is rebound. The guard therefore kept a null request and refused **every** token — while all thirteen refusal checks passed, because "no token" and "bad token" look identical from outside. | The driver closure now pushes the request in directly (`$guard->setRequest($app->make('request'))`) and keeps `refresh()` for long-running workers. Only the positive-case checks caught it. |
| 17 | **`role:admin` guards the Halls management routes and resolves to nothing.** `role` is not a registered alias, and no `RequireRole` class exists to alias in the first place; `bootstrap/app.php` aliases only kernel middleware and states that modules register their own. An anonymous caller gets 401 (because `auth:api` runs first); an **authenticated** caller gets **500** — `Target class [role] does not exist`, logged with `"userId":1`. | **Not fixed** — it needs a decision, not a patch. Recorded in §9 with a permanent repro: `tools/repro-role-alias.php`. |

**New tools:** `tools/verify-live.php` (the checks that would have caught the constraint and route
gaps; reads the running system, so it is not a CI gate), `tools/verify-error-envelope.php` (§7.5) and
`tools/verify-auth-live.php` (§4.4 — the only gate that can tell that `auth:api` throws, because both
faults behind #13/#14 parse cleanly and every route is declared). The two live verifiers self-check
before reporting: against 64 tables / 678 columns, and by asserting the row counts are back where they
started. The repro scripts are `tools/repro-binary-ip.php` (#15) and `tools/repro-role-alias.php`
(#17); each exits non-zero if the behaviour it documents changes, so a fix forces the doc to move.

---

## 9. Known-open, deliberately not changed

**New in this session, and the top of the list:**

- **No module service provider is registered (§8 #13, §4.4).** `Nabilet\Core\NabiletServiceProvider` is
  referenced nowhere; `getLoadedProviders()` returns **0** entries under `Modules\`; `config/nabilet.php`
  names 9 module providers that nobody loads. The application still serves requests because
  `routes/api.php` mounts the module route files with hardcoded `require`s — which is exactly why the
  fault stayed invisible for so long. Two consequences are already visible: `HookRegistry` and
  `OrganizationContext` are not the singletons their docblocks claim, and every module-level middleware
  alias is missing (#17). The fix is not additive: registering the providers while those `require`s
  remain would mount each module's routes twice — once prefixed, once not. The two changes must land
  together.
- **`role:admin` cannot be resolved (§8 #17).** `tools/repro-role-alias.php` measures it: 401 anonymous,
  **500 authenticated**, `Target class [role] does not exist` logged with `"userId":1`. Two remedies
  exist and the choice between them is a design decision, which is why this is recorded rather than
  applied: either build a `RequireRole` middleware and have the module that owns roles register the
  alias (the spec's RBAC is `roles` / `permissions` / `role_permissions`), or move these routes onto the
  `permission` gate the kernel already ships (`RequirePermission`, aliased, fail-closed). The second is
  a one-line change but it changes the authorization *model* on those endpoints, and the spec's OpenAPI
  expresses only `bearerAuth` — it does not settle which of the two the HTTP layer should use.
- **The four `UserService` methods and the `AuthController` rewrite (§4.4).** The guard half is built;
  the module is still a facade. `registerCustomer`, `sendPasswordResetLink`, `resetPassword` and
  `verifyEmail` do not exist, and `AuthController` still returns the flat
  `{"error":"Invalid credentials"}` — the last `E1` ratchet exception.
- **Password reset and email verification have no storage.** `password_reset_tokens` does not exist in
  the live database (0 columns) although `config/auth.php` names it. The plan is a signed stateless
  token — an HMAC over user id + email + expiry — rather than a table the spec does not define.
- **`/api/v1/cart` vs `/api/v1/carts` is structural, not a rename.** The spec models a cart as a
  resource addressed by `{cart}`; the app keys the cart by `session_id` in the body/query and has no
  cart id in the URL at all. `GET /api/v1/carts/{cart}` cannot be produced by adding a prefix — the
  controller and the service signature both assume a session key. Recorded, not patched.
- **`/api/v1/checkin/{validate,use,sync}` vs `/api/v1/tickets/checkin/{scan,verify}`** — same class
  of mismatch, different namespace. The spec's checker API is also the one the Android client needs,
  and it is unbuilt.

**Carried over:**

- **`users.remember_token`** exists in the live DB (1 column of drift) and is not in the spec.
  It was added by committed migration `2026_09_22_001300` (`4fbfd10`). Removing it would
  break Filament's "remember me" on admin login; keeping it means a permanent spec deviation.
  Needs a decision — recorded, not patched.
- **`/api/v1/halls/…` vs `/api/v1/admin/halls/…`.** The spec places these under `admin/`; the
  app declares them public. Adding the segment is a public→admin visibility change, so it is
  recorded rather than applied.
- **Docker daemon is not running** (`open //./pipe/docker_engine` fails). Irrelevant to this
  instance — it connects to PostgreSQL, not the MySQL container — but `docker exec
  nabilet-mysql` is unavailable.
- **The spec's production target is MySQL; this instance runs PostgreSQL.** The invariants are
  now enforced on both (§3), but the deployment still does not match the product. Two dialects
  are maintained in the migrations, and the MySQL path is no longer exercised by anything on
  this machine — so it can rot silently. Either run MySQL or record PostgreSQL as the supported
  target.
- **Filament errors earlier in the day** (in `storage/logs/laravel-2026-09-22.log`):
  `Panel::maxUploadSize does not exist`, `Panel::twoFactorAuthentication does not exist`,
  `Resource::canViewAny($record)` signature mismatch, `Widget::$view` uninitialised,
  `FilamentManager::getUserName(): Return value must be of type string, null returned`.
  `/admin/login` returns 200 now, so these are either fixed or confined to pages not probed.
  Not verified page by page.
- **Log errors from `00:xx`–`11:xx` today** (101 entries) predate the repairs. The
  PostgreSQL-flavoured SQL errors among them name columns the schema does not have
  (`notifications.notifiable_type`, `payments.amount_minor`, `seats.hall_id`) — the same
  invented-field class that `verify-models-schema.php` tracks as 88 known entries.
- **116 implicit-nullable parameters** (`int $x = null` instead of `?int $x = null`) are
  `Deprecated` on PHP 8.4 and invisible to `lint.php`, which reads only `php -l`'s exit code.

---

## 10. Uncommitted work in the tree

The working tree holds **48 entries that are not mine** (68 in total, 20 of which are this session's
work): 33 `app/Models/*`, Filament resources and widgets, `phpunit.xml`, `routes/api.php`,
`OrderService`, `PaymentService`, `TicketScanService`, `AdminPanelProvider`, `.sec3.md`, and untracked
files the application already loads (`app/Modules/Checkin/Domain/CheckinEvaluator.php`,
`app/Modules/Events/Models/Session.php`, `app/Modules/Inventory/Models/SeatHold.php`,
`app/Modules/Venues/Halls/{Domain,Http/Resources,Models}/`, `app/Modules/Webhooks/Http/`).
`HEAD` was `dbb9d59` at the start of this check.

**None of it was committed, reverted or reformatted.** This session's work was committed with explicit
pathspecs, so nothing outside its scope was swept in:

| Commit | Scope |
|--------|-------|
| `a9ba178` | route prefixes, Halls read path, two verifier gaps, `.gitignore` (21 files, +840/−40) |
| `b33ad14` | `SERVER-HEALTH.md` §9 |
| `9fb4d97` | the 35 CHECK constraints and the trigger on PostgreSQL |
| `0cdcff1` | the `user_sessions` auth guard, the `bytea` IP fix, the `role:admin` repro, and the docs for all three (22 files, +2351/−59) |

One of those 22 files needs calling out: **`app/Modules/Auth/routes/api.php` was untracked** before
this session, as were `app/Modules/Venues/Halls/{Domain,Http/Resources,Models}/` and
`app/Modules/Webhooks/Http/`. The auth route file is part of the auth build and was committed with it;
the others were left alone. They deserve attention on their own — the application loads them, so a
fresh clone would not run. The 48 are otherwise not touched here.
