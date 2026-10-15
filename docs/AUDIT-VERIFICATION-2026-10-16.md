# Верификация аудитов — 2026-10-16

**Метод:** сверный проход по трём контрольным аудитам + top-находки `outputs/loyalty-crm-audit.md`; каждый пункт проверен по текущему коду (исследование без правок кода). Доказательства — `файл:строка`. Верификацию выполнял агент сессии 2026-10-16; статусы не «археология», а фактическое состояние main + незакоммиченного дерева.

**Обозначения:** ✅ FIXED · 🟡 PARTIAL · ❌ NOT FIXED

---

## 1. `docs/audit-purchase-flow-2026-10.md` (QA-аудит покупочного контура, 10.10)

| ID | Дефект | Статус | Доказательство |
|---|---|---|---|
| D-1 | Нет polling/reconcile платежей у провайдера | ✅ | `app/Modules/Payments/Console/ReconcilePaymentsCommand.php:42`; расписание `routes/console.php:85` (`everyTenMinutes`); идемпотентный синтетический payload `:179-196`; гоняет через тот же `processWebhook` `:97-100` |
| D-2 | SEAT_HOLDS_EXPIRED без авто-возврата, вечный ретрай вебхука | ✅ | `PaymentService.php:547-557` — терминальный путь `recordReceiptAndRefund`; авто-refund `:709-742` + `RefundService.php:43-186`; claim помечается processed `:512-515` |
| D-3 | Fail-closed вебхук-авторизация; мёртвый путь подписи | 🟡 | Конфиг-путь исправлен: `YooKassaProvider.php:270-288` читает `nabilet.payment.yookassa.webhook_secret` (`config/nabilet.php:256`) и fail-closed. Двухслойная проверка `WebhookAuthenticator.php:26-89`. **Остаток:** ProductionSafetyAudit `:65-69` не принуждает непустой allowlist/секрет — пустая конфигурация по-прежнему блокирует канал (by design, но гейтов нет) |
| D-4 | Нет каскада отмены мероприятия | 🟡 | Отказ с проданными местами: `EventPublicationPolicy.php:75-99` (+принуждение `EventPublicationService.php:97-114`). Сам каскад (revoke/возвраты/уведомления) отсутствует; `EventService::cancel` без защиты не имеет вызовов |
| D-5 | `isHoldConvertible()` читает холд без lockForUpdate | ❌ | `SeatHoldLifecycle.php:55-71` — `DB::table(...)->first()` без блокировки; `PaymentService.php:890-896` тоже |
| D-6 | Промокоды check-then-act | 🟡 | `limit_total` атомарен: `PromoCodeService.php:240-256` (условный UPDATE + 409 PROMO_CODE_EXHAUSTED). `per_user_limit` по-прежнему check-then-act `:277-287` |
| D-7 | `RuntimeException` вместо доменной ошибки при выпуске | ❌ | `TicketService.php:46-48` |
| D-8 | Checkout после timeout → 404 без идемпотентного повтора; UI без ветки 404 | ❌ | `CartCheckoutService.php:64-71` `firstOrFail`; `CheckoutPage.vue:164-215` обрабатывает 410/422/403, но не 404 |
| D-9 | Гостевой токен без хеша/ротации | ❌ | `CartToken.php:28-48`, сравнение в открытом виде `CartCheckoutService.php:68` |
| D-10 | Письмо оседает в failed_jobs без resend/эскалации | ❌ | `SendOrderNotificationJob.php:37-40` — без `failed()`/retryUntil/resend |
| D-11 | Классный `HOLD_SECONDS=600` расходится с серверными 900с | ❌ | `resources/js/stores/cart.ts:34` (`10*60`) vs `config/nabilet.php:208` (15 мин); `OrderSummary.vue:69` уже на 900 |
| D-12 | `/ping` при мёртвой БД | ✅ | Принято и задокументировано; настоящий `GET /health` — `app/Modules/System/routes/api.php:27`, `HealthController.php:43-55` (503 при проблемах) |

## 2. `docs/AUDIT-ARCHITECTURE-DATA-MODEL-2026-10-10.md`

| ID | Пункт | Статус | Доказательство |
|---|---|---|---|
| D5 (arch) | Вечный ретрай вебхука при истёкшем холде | ✅ | см. D-2 выше; терминальный путь не роняет claim (`PaymentService.php:427,468-479,512-515`) |
| D1 (arch) | Гонка в админском `createOrder` | ✅ | `OrderService.php:50-53` `lockForUpdate` + `DomainRuleViolation` `:60-66`; без seat_holds — допустимо по design (`PaymentService.php:884-888`) |
| D2 (arch) | Нет UNIQUE(session_id, seat_id) на tickets | ❌ | Миграции и все три бандла схемы содержат только 4 прежних уникальных индекса (`2026_09_20_000500_005_payments_tickets.php:127-130`, `nabilet_core_spec/migrations.sql:616-619`); смягчение — детерминированный выпуск `TicketIssuance.php:18` |
| D3 (arch) | Нет FK orders→cart/session/event; CASCADE на payments/refunds/promo | ❌ | `2026_09_30_000100:20-25` — колонки без FK; `2026_09_20_000900:162,177,182` — CASCADE; спека это же предписывает (`migrations.sql:533,570,1200`) |
| D4 (arch) | Deadlock'и: разный порядок блокировок, нет retry 1213 | ❌ | Порядок по-прежнему различается (`CartItemService.php:94-98` → `CartCheckoutService.php:65-71` → `HoldSweeper.php:85-100`); retry-декоратора нет |
| D6 | Молчаливые пропуски CHECK/триггеров | ✅ | WARNING в stderr при skip `2026_09_20_001000:34-45,97-100`; CI-гейт: применение обоих бандлов на MySQL 8.4 + диф + проверка триггера (`.github/workflows/ci.yml:198-342`), `tools/verify-state-machines.php` |
| D7 | Нет сверки валюты в addItem | ❌ | `CartItemService.php:58-180` — сравнения нет |
| D9 | `EnsureIdempotency` зарегистрирован, но не применён к роутам | ❌ | Псевдоним `bootstrap/app.php:145`; ноль применения в `routes/` |
| D10 | Revenue-отчёт во float | ❌ | `OrderService.php:292-295`, `OrderRepository.php:165-171` — `(float) sum()` |
| X1 | Дрейф трёх бандлов схемы | ✅ | `tools/verify-migrations.php:18-31` + `tools/verify-contract-schema.php`; CI `.github/workflows/ci.yml:60-84,228-234` (byte-identical апсерт, «Reconcile the three schema bundles» 01137b82) |
| X2 | Осиротевшая `event_dates` | ❌ | `database/migrations/2026_09_22_001400…`, только плоские модели (`app/Models/EventDate.php:19`) |
| — | Дублирование плоских моделей | ❌ | `app/Models` = 54 файла против 65 модульных; 17 файлов модулей всё ещё импортируют плоские |

## 3. `outputs/frontend-spa-audit.md` (SPA, 05.10)

| ID | Пункт | Статус | Доказательство |
|---|---|---|---|
| B2 | Восстановление корзины пишет неверный id; checkout не гидратирует meta | 🟡 | Запись исправлена: `useSeatHolds.ts:72` (cart_items.id), выборка `SeatSelectionPage.vue:87`. **Остаток:** `CheckoutPage.restoreCart()` `CheckoutPage.vue:120-153` по-прежнему не заполняет `cart.meta`; ветка count>0 `useSeatHolds.ts:108-141` тоже — после F5 на чекауте снятие места не релизит холд |
| B3 | Количество стоячих зон + суммы | ✅ | `CoordSeatMap.vue:55,260` (qty), `useSeatHolds.ts:291-300`, `stores/cart.ts:31,46-59,152-155`; lib/inventory.ts:152-160 |
| B3b | Потеря доменного конверта ошибок | ✅ | `lib/api.ts:137-155,158-172`; тесты `tests/Unit/Api/errorEnvelope.test.ts` |
| C2 | Результат платежа доверяет query-параметру; «Обновить статус» не опрашивает сервер | ❌ | `PaymentResultPage.vue:77` — фолбэк на success; статус не fetch'ится; кнопка ведёт на /tickets (`:65`) |
| C3 | Статические билеты-фикстуры | ✅ | `TicketsPage.vue:19,29-41` — `fetchMyTickets()`; `lib/mock.ts` удалён |
| D1 | Админ-заглушки и демо-KPI | ✅ | Все разделы на живом API (`AdminTicketsPage.vue:94`, `AdminPaymentsPage.vue:92`, `AdminUsersPage.vue:77,115`, `AdminIntegrationsPage.vue:64,94`, `AdminAnalyticsPage.vue:53-54`, дашборд `AdminDashboardPage.vue:75-109`) |
| D2 | Guard только по наличию токена | 🟡 | Ошибка логина больше не хранит токен: `lib/auth.ts:29-33`. **Остаток:** guard `router/index.ts:113-118` без проверки роли — валидный токен не-staff-роли проходит в шелл |
| D3 | DOM-XSS в `public/hall-editor.html` | ✅ | Файл удалён; редактор — Vue-страница `HallEditorPage.vue`; `innerHTML`/`v-html` в `resources/js` отсутствуют |
| E1 | Metrika-конфиг 404 | ✅ | `app/Modules/Analytics/routes/api.php:12-21` подключён к центральному роутеру `routes/api.php:73-76` |
| E2 | Мёртвые цели воронки | ❌ | По-прежнему только `page_view` (`main.ts:30`), `seatmap_open` (`useSeatInventory.ts:89`), `seat_selected`; `event_view/checkout_started/payment_*` без call sites |
| E3 | SEO-метаданные | ✅ | Решено серверным SEO-контуром: `EventPageController.php:207-262,704-716` (title/description/canonical/og/JSON-LD) на реальных URL `/event/{slug}` (`app/Modules/Events/routes/web.php:14-15`) |

## 4. `outputs/loyalty-crm-audit.md` (top-находки)

| Пункт | Статус | Доказательство |
|---|---|---|
| HTTP-слой промокодов отсутствует | ✅ | `app/Modules/Pricing/routes/api.php` (6 эндпоинтов, public validate с throttle `:25-26`), интеграция в чекаут `CartCheckoutService.php:150-180,228-236` |
| `Orders/Models/PromoCode.php` читает несуществующие колонки | ✅ | Модель переписана на реальные колонки `:16-55` + `toDefinition()` |
| Автосоздание Customer при guest-покупке | 🟡 | Домен есть (`Users/Domain/UserIdentity.php`, `UserIdentityPolicy.php:36-103`), но не подключён к checkout (`CartCheckoutService.php:193` всё ещё `user_id => null`) |
| `NewsletterService` читает несуществующую колонку `users.subscribed_to_newsletter` | ❌ | `NewsletterService.php:25`; колонки нет ни в одной схеме — SQL-ошибка в рантайме при вызове |
| Бонусы/уровни/рефералы | ❌ | Не реализовано (по плану — этап 3, P3) |

---

## Итог и оставшиеся работы

**Закрыто:** все три Blocker'а покупочного контура (reconcile-джоба, авто-refund, подпись вебхуков — контроль ссылок), гонка админского заказа, дрейф схемы (CI-гейты), CHECK/триггер-верификация, SPA-заглушки админки, статические билеты, Metrika-роут, SEO-поверхность, DOM-XSS, конверт ошибок и количества стоячих зон на фронте.

**Остатки (в порядке риска):**
1. **Backend:** D-5 (lock при конвертации холда в grace-окне), D7-arch (валюта), D-10 (email resend), D-9 (ротация гостевого токена), D-7 (доменная ошибка выпуска), D-8 (идемпотентный checkout), D9-arch (idempotency-middleware на POST), D10-arch (float в отчётах), D2-arch (UNIQUE tickets), D3-arch (FK/CASCADE), D4 (каскад отмены — сейчас только rejection-guard), per_user_limit (гонка), `NewsletterService` (несуществующая колонка), дедуп плоских моделей, `event_dates`.
2. **Frontend:** C2 (доверие query-параметру результата платежа + «Обновить статус» без сервера), B2-остаток (`restoreCart` без `cart.meta`), D2-остаток (guard без роли), E2 (мёртвые цели воронки), D-11 (HOLD_SECONDS=600).

Пункты помечены по таблицам выше; до пуша прогнаны `php tests/run.php` (700/700), `npx vitest run` (267/267), `vue-tsc --noEmit`, `npm run build`.
