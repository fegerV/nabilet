# NABILET Core — server health check

**Date:** 2026-09-22 · **Scope:** the running instance on this machine, not a code review.
**Method:** live HTTP probes, `artisan`, and direct queries against the connected database.
Every number below was measured, not inferred.

Reproduce everything here with:

```bash
PHP=C:/Users/Professional/AppData/Local/VertexCMS/tools/php/php.exe
$PHP tools/verify-live.php      # new: live DB + route table vs spec
$PHP artisan route:list --path=api/v1
$PHP artisan migrate:status
```

---

## 1. Verdict

The server **boots, connects, and serves traffic**. It is **not fit to face users**, for
four reasons that are independent of each other — any one of them alone would block launch.

| # | Finding | Severity | Evidence |
|---|---------|----------|----------|
| 1 | The database enforces **none** of the spec's CHECK constraints, and the immutability trigger is absent — while `migrate:status` reports every migration as `Ran` | **P0** | `check live 0 / spec 35`, `trigger live 0 / spec 1` |
| 2 | `laravel/sanctum` is not a dependency, but `auth:sanctum` guards 7 routes → **every authenticated endpoint returns 500** | **P0** | `Auth guard [sanctum] is not defined.` |
| 3 | `APP_ENV=production` with **`APP_DEBUG=true`** → full stack traces with absolute filesystem paths returned to callers | **P0** | `GET /api/v1/halls/xyz` returns a traced exception body |
| 4 | **67 of the spec's 81 API paths are not served** | **P0** | `tools/verify-live.php` §2 |

---

## 2. What works

| Check | Result |
|-------|--------|
| PHP dev server on `127.0.0.1:8000` | listening (PID 28932, PHP 8.4.21) |
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
| `GET /api/v1/venues/1/halls` | 200, paginated JSON — the Halls read path works end-to-end against the live DB |
| Full lint | 505 files, 0 syntax errors |
| Test suite | 596 tests, 0 failed, 1216 assertions |
| All other verifiers | purity 99 files/0 violations · openapi 11/11 · contract-schema 28/0 drift · migrations 13/0 · state-machines · models-schema · autoload · module-structure — all PASS |

---

## 3. P0 — the database does not enforce the spec

**The migrations are correct for MySQL and were run against PostgreSQL.**

`database/migrations/2026_09_20_001000_add_check_constraints.php` guards all 35
`ALTER TABLE ... ADD CONSTRAINT ... CHECK` statements behind:

```php
return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
```

and `…_001100_add_schema_version_immutability_trigger.php` does the same for the one
trigger. `.env` says `DB_CONNECTION=pgsql`, so both migrations take the early-return path.

**A migration that returns early still counts as run.** `migrate:status` shows all 13 as
`Ran`, and `tools/verify-migrations.php` passes 13/13 — because that verifier parses the
migration *files*, not the database. Two independent green signals over a database that
enforces nothing:

```
check    live    0   spec   35   MISMATCH
trigger  live    0   spec    1   MISMATCH
```

What is therefore unenforced right now: every status enum (`ck_orders_status`,
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

**Remedy applied:** 001000 now writes the same kind of loud stderr warning as 001100, naming
the driver and stating that `migrate:status` will still say `Ran`. Verified by invoking the
migration directly on the live connection.

**Still open — needs a decision, not a patch:** either run MySQL (what the spec describes),
or port the 35 CHECK constraints to PostgreSQL (the expressions are portable) and rewrite the
trigger as a `PL/pgSQL` function. Do not leave a deployment whose database enforces none of
the contract.

---

## 4. P0 — authentication is not installed

`auth:sanctum` appears 7 times across 5 route files. `laravel/sanctum` appears:

- in `composer.json` `require`: **no**
- in `composer.lock`: **0 occurrences**
- in `vendor/laravel/`: **absent** (only framework, pint, prompts, serializable-closure, tinker)

`config/auth.php` defines only the `web` guard. So every guarded endpoint dies before it
reaches a controller:

```
GET /api/v1/users → 500
{"message":"Auth guard [sanctum] is not defined.","exception":"InvalidArgumentException"}
```

Affected: Users, Organizations, Payments (index/show), and the protected half of Halls.

**Not fixable in this environment** — there is no network access to Packagist, so the
package cannot be installed and `composer.lock` cannot be regenerated. This must be fixed
where dependencies can be resolved. The spec's security scheme is `bearerAuth`, which is
what Sanctum issues, so the dependency is genuinely required rather than an alternative to
be swapped out.

---

## 5. P0 — production with debug on

`.env`: `APP_ENV=production`, `APP_DEBUG=true`.

Every unhandled error is returned to the caller as a traced JSON body with absolute
filesystem paths (`C:\Project\nabilet\...`), class names, and line numbers. For a
multi-tenant B2B system handling payments and personal data, that is an information
disclosure channel, and it is the reason the errors in §7 are visible at all.

Set `APP_DEBUG=false` before this instance is reachable by anyone. Errors should be
rendered through the §66 envelope (which `bootstrap/app.php` already implements for
`AppError` and deliberately uses a generic 500 for `TenantContextMissingError`).

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
| `/api/v1/carts/*` | 4 | app models a *single* session cart (`/cart`), the spec models a cart resource |
| `/api/v1/promo-codes/*` | 3 | |
| `/api/v1/media/*` | 2 | |
| translations (`/venues/…`, `/pages/…`) | 4 | |
| auth password/email paths | 3 | app has `/auth/forgot-password`; spec has `/auth/password/forgot` |
| OAuth callbacks, telegram, health, webhooks/yookassa, others | 5 | |

Some of these are genuine missing features; some are naming mismatches (cart vs carts,
`forgot-password` vs `password/forgot`). They need to be separated before the roadmap is
re-costed — see `docs/REVIEW-spec-bundle.md`.

---

## 7. Defects found and fixed

| # | Defect | Fix |
|---|--------|-----|
| 1 | **53 routes carried a second version prefix** — `/api/v1/v1/events`, `/api/v1/api/v1/halls`. The global `apiPrefix` in `bootstrap/app.php` already mounts every module file at `/api/v1`, and 11 module route files repeated the segment. Only 3 of 81 spec paths were reachable. | Removed the duplicate segment from all 11 files. Spec paths served went **3 → 14**; zero doubled routes remain. |
| 2 | **`HallController` reached into a protected property.** `$this->service->repository->…` in 7 places; `HallService::$repository` is `protected` → `Cannot access protected property`, so *any* hall read returned 500. | Added `findByVenue` / `findByPublicId` / `getSchemaVersions` to `HallService`; controller now calls those. |
| 3 | **`HallRepository::findByVenue` declared `Collection` but returned a paginator** → `TypeError` on the one endpoint that worked. | Corrected the return type to `LengthAwarePaginator` (the `paginate()` call is intended — `HallCollection` is a `ResourceCollection`). |
| 4 | **`verify-models-schema.php` reported a false positive.** It read only a file's own text for `$table`, so four thin alias models that inherit `$table` from a sibling (`Halls\Models\Hall extends Venues\Models\Hall`) were reported as "no `$table`" and the gate failed on correct code. | The verifier now follows `extends` (resolving `use … as …` aliases) up to 4 levels. Mutation-tested in both directions: a bogus parent `$table` is reported against *both* the parent and the alias; an unresolvable parent still fails as "no `$table`". 67 models now checked, gate green. |
| 5 | **`verify-migrations.php` crashed (exit 255)** on `2026_09_22_001300_add_remember_token.php`: the Laravel stub had no `rememberToken()`, so the whole gate aborted and every later migration went unchecked. | Added `rememberToken()` to `tools/laravel-stub.php`. Gate now 13/13. |
| 6 | **`.gitignore` had lost its protections again** (4th occurrence). `Мысли о SEO.txt` — an internal document — was untracked and **not** ignored, one `git add -A` away from publication. No `*.pem` / `*.key` / `id_rsa*` / `.ssh/` patterns either. | Restored the private-document and key-material patterns, plus `!.env.example`. Verified: the document is now ignored, `.env.example` and `composer.lock` remain tracked (they are already in the index), CRLF preserved (135 CRLF / 0 bare LF). No key material exists on disk, so nothing was exposed. |

**New tool:** `tools/verify-live.php` — the checks that would have caught findings 1 and the
route gap. It reads the running system (booted app, connected DB, registered route table)
rather than the repository, and it is **not** part of the CI gate because it needs a live
environment. It self-checks its spec parser against 64 tables / 678 columns before
reporting, because an under-reading parser invents drift.

---

## 8. Known-open, deliberately not changed

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
- **Filament errors earlier in the day** (in `storage/logs/laravel-2026-09-22.log`):
  `Panel::maxUploadSize does not exist`, `Panel::twoFactorAuthentication does not exist`,
  `Resource::canViewAny($record)` signature mismatch, `Widget::$view` uninitialised,
  `FilamentManager::getUserName(): Return value must be of type string, null returned`.
  `/admin/login` returns 200 now, so these are either fixed or confined to pages not probed.
  Not verified page by page.
- **Log errors from `00:xx`–`11:xx` today** (101 entries) predate the repairs. The
  PostgreSQL-flavoured SQL errors among them name columns the schema does not have
  (`notifications.notifiable_type`, `payments.amount_minor`, `seats.hall_id`) — the same
  invented-field class that `verify-models-schema.php` tracks as 88 known entries. The log
  has been quiet since 11:38.
- **116 implicit-nullable parameters** (`int $x = null` instead of `?int $x = null`) are
  `Deprecated` on PHP 8.4 and invisible to `lint.php`, which reads only `php -l`'s exit code.

---

## 9. Uncommitted work in the tree

The working tree holds **48 changed entries that are not mine** — 33 `app/Models/*`, Filament
resources and widgets, `phpunit.xml`, `routes/api.php`, `OrderService`, `TicketScanService`,
`AdminPanelProvider`, and untracked directories the app already loads
(`app/Modules/Auth/routes/`, `app/Modules/Venues/Halls/{Domain,Http/Resources,Models}/`,
`app/Modules/Webhooks/Http/`). `HEAD` was `b1b333e` (PR #39) at the start of this check.

**None of it was committed, reverted or reformatted.** The fixes in §7 were committed as
`a9ba178` (21 files, +840/−40) using explicit pathspecs, so nothing outside this report's scope
was swept in. Pushed to `main` (`b1b333e..a9ba178`).

The untracked directories deserve attention on their own: the application loads them, so a
fresh clone would not run.
