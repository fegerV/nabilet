# Internal Modules Documentation

## Modules Without Public Routes

The following modules are registered via ServiceProviders but do not expose public API routes.
They are designed for internal use only.

### Core Infrastructure Modules

| Module | Provider | Purpose |
|--------|----------|---------|
| `Auth` | `AuthServiceProvider` | Authentication logic, guards, middleware |
| `Security` | `SecurityServiceProvider` | Security policies, encryption, rate limiting |
| `System` | `SystemServiceProvider` | System configuration, health checks |
| `Installer` | `InstallerServiceProvider` | Installation wizard, setup routines |

### Support Modules

| Module | Provider | Purpose |
|--------|----------|---------|
| `AbTesting` | `AbTestingServiceProvider` | A/B test configuration, variant selection |
| `Ai` | `AiServiceProvider` | AI integration, prompt handling |
| `Backups` | `BackupsServiceProvider` | Backup scheduling, storage management |
| `Content` | `ContentServiceProvider` | CMS content management |
| `Embed` | `EmbedServiceProvider` | Embeddable widget generation |
| `HallSchemas` | `HallSchemasServiceProvider` | Hall layout definitions |
| `Heatmaps` | `HeatmapsServiceProvider` | Heatmap data collection |
| `Localization` | `LocalizationServiceProvider` | i18n/l10n support |
| `Media` | `MediaServiceProvider` | Media asset management |
| `Notifications` | `NotificationsServiceProvider` | Notification dispatch |
| `Pricing` | `PricingServiceProvider` | Pricing rules engine |
| `Privacy` | `PrivacyServiceProvider` | GDPR/privacy compliance |
| `Seo` | `SeoServiceProvider` | SEO metadata management |
| `Telegram` | `TelegramServiceProvider` | Telegram bot integration |
| `Users` | `UsersServiceProvider` | User management (internal) |

## Modules With Public Routes

The `Analytics` module (`AnalyticsServiceProvider`) exposes one public endpoint:

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/analytics/metrika/config` | none | Yandex Metrika counter ID and goal names for the SPA (Yandex Direct attribution). Serves only non-secret fields (`MetrikaSettings::publicConfig()`); settings come from `config/metrika.php` (env) merged with DB overrides in table `metrika_settings`. See `docs/ROADMAP.md`, stage 5. |

### `Loyalty` — «Клуб зрителей» (`LoyaltyServiceProvider`)

Phases 1 and 2a of `docs/LOYALTY-PLAN.md`: favorites and follow-subscriptions of a
buyer, plus the bonus account (cashback balance and its journal).
All eight paths live under `auth:api` and take the owner from the Bearer token —
none of them accepts a foreign user id, so cross-account access is impossible by
construction. The event in the path is addressed by `public_id` (ULID), not by
the internal BIGINT (`LoyaltyController::resolveEvent()`); the internal id never
leaves the API (`DATABASE.md` §9).

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/me/favorites` | `auth:api` | Favorites, newest first |
| POST | `/api/v1/me/events/{event}/favorite` | `auth:api` | Add to favorites — 201 first time, 200 on repeat (`uq_event_favorites_user_event`) |
| DELETE | `/api/v1/me/events/{event}/favorite` | `auth:api` | Remove — 204 even on repeat |
| GET | `/api/v1/me/follows` | `auth:api` | Subscriptions to e-mail about new sessions |
| POST | `/api/v1/me/events/{event}/follow` | `auth:api` | Subscribe — 201/200, same idempotency rule |
| DELETE | `/api/v1/me/events/{event}/follow` | `auth:api` | Unsubscribe — 204 |
| GET | `/api/v1/me/bonus` | `auth:api` | Bonus balance — `balance` in minor units, `bonuses` at the display rate (100); zero for a buyer with no orders |
| GET | `/api/v1/me/bonus/transactions` | `auth:api` | Append-only bonus journal, newest first, paginated (`data` list + `meta`) |

Favorites and follows are deliberately separate tables: a favorite is memory
(«хочу посмотреть») and produces no mail, a follow is a request for a letter
(`SessionObserver` → `NotifyFollowersOfNewSessionJob`, journal
`follow_notifications` guards against a repeat send).

Bonus accrual (phase 2a) hangs off the **same** `Order` model, not off
`OrderService`: `OrderBonusObserver::updated()` fires on any transition into
`paid` and queues `AccrueOrderBonusJob` `afterCommit()`. A second observer on one
model is supported — Laravel appends to the model's observer list, so the Orders
module keeps its own `OrderObserver` (mail + webhooks) and never learns about the
bonus account. `BonusService::accrueForOrder()` is the only writer of
`bonus_accounts.balance`, and idempotency is granted by the unique key
`uq_bonus_transactions_dedupe` (`dedupe_key = accrual:{order_id}`) rather than by
a `SELECT`-then-`INSERT`, which a second worker could slip between.

Routes are **not** loaded by the module provider: the single owner of route
registration is `routes/api.php` (`tools/verify-route-ownership.php`). The
provider exists to boot the observers — an unregistered provider means a
`Session` model with no `created` listener (no subscriber e-mails) and an `Order`
model with no bonus accrual, both silently.

Storefront screens: `/favorites`, `/subscriptions` and `/bonus`
(`resources/js/pages/storefront/`, guarded by `meta.requiresCustomer`), plus the
«♡» on `EventCard` and «♡»/«🔔 Следить» on `EventPage`. The bonus balance is part
of `stores/loyalty.ts` (loaded together with both lists by the storefront shell),
so the header chip, the account tile and the `/bonus` screen all read one number
and cannot disagree. Spending bonuses at checkout is implemented in phase 2b and is
offered on the checkout screen (`CheckoutPage` with a «Списать N бонусов» toggle,
`lib/loyalty.ts::spendCapMinor` mirroring the server-side cap).

## Special Cases

| Module | Provider | Notes |
|--------|----------|-------|
| `Checkin` | `CheckinServiceProvider` | May have routes in separate file |
| `Core` | `CoreServiceProvider` | Base services, shared utilities |
| `Nabilet` (root) | `NabiletServiceProvider` | Application bootstrap |

## Usage Guidelines

1. **Do not add public routes** to these modules unless there is a clear API requirement.
2. **Use dependency injection** to access services from these modules.
3. **Document any new internal module** in this file when created.
4. **Keep business logic** in Services, not Controllers.

## Architecture Decision

These modules follow the **Internal Service Pattern**:
- No public HTTP endpoints
- Accessed via service containers
- Used by other modules through interfaces
- May have background jobs, events, listeners
