# NABILET Vue 3 SPA — Frontend Audit

**Audit target:** `C:/Project/nabilet` at commit `1611bda1` (`fix: привести модели и репозитории билетов к контракту`).

**Scope:** Vue 3 / TypeScript SPA, Vite build and Laravel `dist/` delivery, storefront, cart/checkout/payment/tickets, admin UI and guards, Metrika, SEO metadata, responsive behavior, and frontend XSS sinks.

## Executive verdict

The frontend builds cleanly and the test/type gates pass. The Laravel root serves the fresh build and its assets, and tested hash routes load on a cold browser navigation. The storefront catalogue is API-backed; seat selection and checkout also call real APIs in source. However, the most visible production gaps are **unverified payment success UI**, **static sample tickets instead of purchased tickets**, and **cart restoration/quantity mismatches**. Metrika is currently disabled in the live app because its config endpoint returns 404. Admin dashboards and several sections remain demo/placeholder UI.

No application source files were changed in this audit. The full purchase path was not executed against the live local server because it would create holds, orders, and payment records.

## Verification performed

- `npm install --no-audit --no-fund`: exited 0; npm warned that safe-delete blocked cleanup of two stale nested package directories. Install completed.
- `npm run build`: PASS — Vite 6.4.3, 287 modules, production output in `dist/`.
- `npm run typecheck`: PASS — `vue-tsc --noEmit`.
- `npm test`: PASS — 3 files, 50 tests. Vitest printed expected jsdom `window.alert()`-not-implemented and intentional network-error test logs; no test failed.
- Live HTTP: `/` 200; built JS/CSS assets 200; `/api/v1/ping` 200; published events API 200 with one published test event; `/api/v1/analytics/metrika/config` 404.
- Headless browser: cold hash navigation to `#/tickets` rendered fixture tickets; `#/payment/unknown-result` rendered **“Билеты готовы”**; unauthenticated `#/admin/orders` redirected to `#/admin/login?redirect=/admin/orders`. No uncaught JS exceptions were captured on those routes.
- Viewport overflow check: storefront 360 px, ticket page 390 px, payment page 768 px, admin login 1440 px all had `document.body.scrollWidth <= innerWidth`. This is a smoke check, not a full visual/accessibility review.
- `dist/` is not tracked (`git ls-files dist` returned 0). Missing-`dist` behavior was inspected in source, not simulated by removing/renaming the live build.

## A1–E3 audit matrix

Evidence labels: **Live** = observed via HTTP/browser; **Source** = confirmed in code/contracts; **Partial** = limited to the tested route/path. Priority: P1 highest, P3 lowest.

| ID | Area | Result | Priority | Finding |
|---|---|---|---|---|
| A1 | Build and tests | PASS | — | Production build, TypeScript check, and all 50 Vitest tests pass. |
| A2 | Laravel delivery | PASS / partial | P2 | `/` and built assets return 200. `dist/` is untracked, so a release must build it on the target host; source returns 503 `STORE_FRONT_NOT_BUILT` when the index is absent. That 503 branch was not exercised live. |
| A3 | Hash routing and responsive smoke test | PASS / partial | P3 | Cold `#/tickets` route rendered; admin deep route redirected to login. No horizontal overflow on the four tested viewport widths. This does not cover every page/device. |
| B1 | Catalogue and event detail | PASS / partial | P3 | Catalogue fetches `/events?status=published&per_page=100`; live request returned 200. Event detail also fetches by slug. Its seat-map preview is generated synthetic data, not real layout/availability. |
| B2 | Holds and cart restoration | FAIL | P1 | Restoring a cart stores the inventory ID as `cart.meta` even though DELETE requires the cart-item ID; checkout restoration hydrates seats without restoring `meta`. Restored selections can therefore fail to release their server hold. |
| B3 | Quantity and API errors | FAIL | P1 | Standing-zone quantity is sent to the API but the local cart stores one seat per zone ID and totals one unit. The normal API client also drops the backend's nested validation message/field errors. |
| C1 | Checkout request | PASS / unverified | P2 | Current source sends name, email, phone and uses the server-returned order ID/total. This is no longer the old blank-email or `setTimeout` flow. No live order was created to test end-to-end. |
| C2 | Payment result | FAIL | P1 | Unknown/missing result values fall back to success; browser reproduced “Билеты готовы” at `#/payment/unknown-result`. The result page does not fetch authoritative payment/order status; “Обновить статус” goes to tickets instead of polling. |
| C3 | Purchased tickets | FAIL | P1 | `TicketsPage.vue` reads static `TICKETS` fixtures and never calls the API; “Отправить ещё раз” has no handler. Ticket read routes require authentication and scope tickets to `order.user_id`, while storefront checkout creates guest orders with `user_id = null`. |
| D1 | Admin feature coverage | PARTIAL | P2 | Orders/events/sessions/venues/halls have real pages, but tickets/payments/users/integrations/analytics lead to an explicit placeholder. Dashboard metrics/orders, organization selector, and Orders badge `12` are hardcoded demo data. |
| D2 | Admin authorization and credentials | FAIL / conditional | P1 | SPA guard checks token presence only. A non-staff login stores the token before role denial and does not clear it, so the user can enter the client shell. Backend admin middleware still protects privileged routes. Demo credentials are visible in the UI and seeders; exposure impact depends on deployment seeding/password changes. |
| D3 | XSS and mobile | PARTIAL | P2 | No raw HTML sink was found in `resources/js`. Legacy standalone `public/hall-editor.html` interpolates imported object names into `innerHTML`, creating a file-import DOM-XSS risk if a victim imports a crafted schema. Tested viewport widths had no horizontal overflow. |
| E1 | Metrika configuration | FAIL | P1 | Browser and HTTP both observed `/api/v1/analytics/metrika/config` → 404. Client treats non-2xx as disabled, so the configured counter is not initialized. Analytics routes are absent from the central route list/provider wiring. |
| E2 | Funnel goals | FAIL | P2 | Only `page_view`, `seatmap_open`, and `seat_selected` have SPA call sites. `event_view`, `checkout_started`, `payment_attempt`, `payment_success`, and `payment_fail` are configured but unused. |
| E3 | SEO metadata | PARTIAL | P2 | Hash SPA serves one static title/description from `index.html`; no route-level title/meta update was found. `EventPage` receives SEO fields but does not apply them to document metadata. A separate server SEO route exists, so this is specifically a hash-SPA limitation, not a claim that the whole site has no SEO route. |

## UI↔API расхождения после PR #59–61

### Current behavior that is aligned (source-confirmed)

- Checkout calls `POST /cart/checkout` with `customer_name`, `customer_email`, and `customer_phone`; then calls `POST /payments` using the server's `order_id` and displays the server total. This contradicts the older blank-email/simulated-payment description.
- Checkout returns `order_id` as the order public ID; the payment controller resolves both public IDs and numeric internal IDs.
- The shared API client adds `X-Cart-Token` to `/cart*` requests and forwards an existing token to payment requests. The payment controller checks the guest token against the order's cart. This contract is aligned in source; a real payment request was not issued.
- Cart deletion is scoped by both cart token and cart ownership on the backend. `releaseSeat()` sends a numeric-shaped session ID string, which is compatible with Laravel's integer validation.

### Confirmed mismatches

1. **Restored cart ID:** `CartItemResource.id` is the database ID of the cart item; `inventory_item.id` is the inventory public ID. `SeatSelectionPage.applyServerCart()` resolves an inventory row and stores its internal inventory ID in `cart.meta`, then passes that value as the cart-item ID to `DELETE /cart/items/{id}`. `CheckoutPage.restoreCart()` hydrates selected seats but never hydrates `cart.meta`. This can leave a server hold in place after removing a restored selection.
2. **Standing ticket quantity:** `CoordSeatMap` offers 1–5. `onToggleCoord()` sends `qty` to `holdSeat()`, but `CartSeat` has no quantity field, `count` is selected-ID cardinality, and `subtotalMinor` adds one unit price per selected ID. Backend `CartService` increments and totals the requested quantity. UI quantity/price can be lower than the checkout/order total.
3. **Validation error envelope:** backend responds as `error.message` and `error.details.fields`; `lib/api.ts` reads top-level `message` and `error.errors`. Consequently checkout field errors can disappear and display generic `HTTP 422` text rather than field-specific validation.
4. **Ticket visibility:** the visible ticket screen is fixture-driven, while the current ticket read API is bearer-authenticated and scopes ordinary users by `order.user_id`. Guest purchases cannot appear in this page as currently implemented.
5. **Payment-result trust:** the route parameter alone selects the result view, with unknown values defaulting to success. No server status lookup is made before displaying the success message.

## Карта моков витрины

| Page / data | Source | Classification | Notes |
|---|---|---|---|
| Catalogue | `GET /events?status=published` | API | Live request returned one published test event. |
| Event detail | `GET /events/by-slug/{slug}` | API + synthetic preview | Event/session metadata is API-backed; 7×20 seat preview and states are generated locally. |
| Seat selection | Inventory API, `GET /cart`, `POST/DELETE /cart/items` | API + local store | Inventory is real; local representation has cart-ID and standing-quantity restoration gaps. |
| Checkout | `POST /cart/checkout` | API | Customer fields and server total are sent/used in source; no write-flow runtime test was performed. |
| Payment | `POST /payments`, demo confirmation endpoint | API + route-derived UI result | API initiation exists; result screen does not read final status. |
| My tickets | `TICKETS` in `resources/js/lib/mock.ts` | Mock | Static active/past sample tickets; no API fetch or resend action. |
| Admin overview | `ORDERS` plus literals in `AdminDashboardPage.vue` | Mock/static | KPIs, chart, tasks, sales channel, organization/month labels are not live analytics. |
| Admin orders | Orders API | API | The page has a live fetch path; a broad admin mutation walkthrough was not run. |

## Мертвые цели Метрики

Configured in `config/metrika.php`: `page_view`, `event_view`, `seatmap_open`, `seat_selected`, `checkout_started`, `payment_attempt`, `payment_success`, `payment_fail`.

**Call sites found:** `page_view` (router tracking), `seatmap_open`, `seat_selected`.

**Configured but dead:** `event_view`, `checkout_started`, `payment_attempt`, `payment_success`, `payment_fail`. The purchase/revenue branch in `lib/metrika.ts` has no caller in the SPA. UTM values are collected/persisted in source, but the observed config 404 disables transmission.

## Other notes and limitations

- Root HTML has a missing `/favicon.ico` request (404) in browser Network; low severity.
- `dist/` and its build output are local/ignored; no committed `dist` could be compared to the fresh build.
- The standalone hall-editor sink is outside the routed Vue SPA. The installer template also uses `innerHTML`, but inspected response fields are constructed from server environment checks; attacker-controlled data flow was not established.
- No source code changes were made. Working tree currently contains generated changes in `node_modules/.package-lock.json`, `node_modules/.vite/vitest/.../results.json`, `node_modules/.vue-global-types/vue_3.5_0_0_0.d.ts`, and `vendor/autoload.php`; the two latter generated files were already noted as locally modified before this audit. They were not reverted.
- Remaining verification that would require controlled test data: add/release a hold and confirm DB state, cart restoration after F5, standing-zone quantity totals, checkout/payment transaction, guest ticket retrieval, admin role-denial/token-expiry flows, and a missing-`dist` runtime probe. The audit deliberately did not create these records.
