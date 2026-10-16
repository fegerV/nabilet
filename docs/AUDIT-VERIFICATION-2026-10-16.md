# Верификация аудитов — 2026-10-16

**Метод:** два прохода. Первый — по локальному дереву до слияния; после слияния `origin/main` (merge `c84e8688`, 38 коммитов удалённой линии: лояльность/кабинет/чекин/платежи) **все спорные пункты пере-проверены по итоговому дереву**. Третий проход — доработка: часть остатков закрыта в этом же коммите (помечено «закрыто 16.10»). Исследование/правки с тестами, доказательства — `файл:строка`. Гейты на дереве: vitest 382/382, vue-tsc чисто, build ok, php hermetic 820/820, verify-migrations/state-machines/models/contract/openapi/lint зелёные.

**Обозначения:** ✅ FIXED · 🟡 PARTIAL · ❌ NOT FIXED · ❓ не проверяемо в этой среде (нужны прод/секреты/нагрузка)

---

## 1. `docs/audit-purchase-flow-2026-10.md` (QA покупочного контура)

| ID | Дефект | Статус | Доказательство |
|---|---|---|---|
| D-1 | Нет reconcile платежей у провайдера | ✅ | `ReconcilePaymentsCommand.php:42`; расписание `routes/console.php:85`; идемпотентный synthetic event `:194` |
| D-2 | SEAT_HOLDS_EXPIRED без возврата, вечный ретрай | ✅ | `PaymentService.php:547-557` → `recordReceiptAndRefund` `:709-742`; claim фиксируется `:512-515` |
| D-3 | Вебхук-авторизация | ✅ | Конфиг-путь `nabilet.payment.yookassa.webhook_secret` (`YooKassaProvider.php:277-288`, `config/nabilet.php:291`); raw-body HMAC; wildcards банятся `ProductionSafetyAudit` |
| D-4 | Каскад отмены мероприятия | 🟡 | Отказ при проданных местах `EventPublicationPolicy.php:85-96`; самого каскада нет (см. R-08) |
| D-5 | `isHoldConvertible()` без блокировки | ✅ закрыто 16.10 | `SeatHoldLifecycle.php:61` `lockForUpdate()` (транзакция вебхука); выборка холдов в `validateHoldsForOrder` тоже под локом (`PaymentService.php:890-897`) |
| D-6 | Промокоды check-then-act | 🟡 | `limit_total` атомарен (`PromoCodeService.php:240-256`); `per_user_limit` всё ещё check-then-act (гонка параллельных чекаутов) |
| D-7 | `RuntimeException` при выпуске билетов | ✅ закрыто 16.10 | `TicketService.php:46-54` — `DomainRuleViolation('ORDER_NOT_PAID', 422)`; тестов на старое сообщение нет |
| D-8 | Checkout 404 вместо идемпотентного повтора | 🟡 закрыто 16.10 (UI) | Сервер по-прежнему 404 (`CartCheckoutService.php:64-71`, firstOrFail); UI честно обрабатывает: `CheckoutPage.vue:299-306` — «корзина устарела, выберите места заново» вместо общей ошибки |
| D-9 | Гостевой токен без хеша/ротации | ❌ | `CartToken.php:28-48`, сравнение открытым текстом |
| D-10 | Письмо оседает в failed_jobs | 🟡 | Resend появился: `POST /my-tickets/resend` (`Orders/routes/api.php:52`) + UI (`TicketsPage.vue:175-204`); авто-эскалации/`failed()` хука нет |
| D-11 | HOLD_SECONDS=600 vs серверные 900с | ✅ закрыто 16.10 | `stores/cart.ts:34-38` — 15*60 + комментарий легаси-фолбэка |
| D-12 | `/ping` при мёртвой БД | ✅ | Задокументировано; `GET /health` с реальными проверками (`HealthController.php:43-55`) |
| (S4/C2) | Результат платежа доверяет query-параметру | ✅ | `PaymentResultPage.vue:114-121` — `refreshStatus()` → `fetchPayment()`, `statusToResult()` |

## 2. `docs/AUDIT-ARCHITECTURE-DATA-MODEL-2026-10-10.md`

| ID | Пункт | Статус | Доказательство |
|---|---|---|---|
| D5-арх | Вечный ретрай вебхука | ✅ | см. D-2; терминальный путь не роняет claim |
| D1-арх | Гонка в админском заказе | ✅ | `OrderService.php:50-53` lockForUpdate + DomainRuleViolation `:60-66`; без seat_holds — допустимо (`PaymentService.php:884-888`) |
| D2-арх | Нет UNIQUE(session_id, seat_id) на tickets | ❌ | Только 4 прежних уникальных индекса; смягчение — детерминированный выпуск (`TicketIssuance.php:18`) |
| D3-арх | Нет FK orders→cart/session/event; CASCADE перс. таблиц | ❌ | `2026_09_30_000100:20-25`; `2026_09_20_000900:162,177,182` |
| D4-арх | Deadlocks (порядок локов, retry 1213) | ❌ | `CartItemService` → `CartCheckoutService` → `HoldSweeper` по-прежнему в разном порядке; retry-декоратора нет |
| D6 | Молчаливые пропуски CHECK/триггеров | ✅ | WARNING при skip; CI применяет бандл на MySQL 8.4 и проверяет триггер (`ci.yml:198-464`) |
| D7-арх | Сверка валюты в addItem | ✅ закрыто 16.10 | `CartItemService.php:150-170` — сравнение с валютами уже лежащих позиций (CURRENCY_MISMATCH 409, откат в транзакции) |
| D9-арх | EnsureIdempotency не применён к роутам | ❌ | Ноль использований в `routes/` |
| D10-арх | Revenue-отчёт во float | ✅ закрыто 16.10 | `OrderService.php:299-305`, `OrderRepository.php:178-190` — int minor units (вызовов больше нет — метод-изолят) |
| X1 | Дрейф трёх бандлов схемы | ✅ | `tools/verify-migrations.php` + CI byte-identity; после слияния бандлы восстановлены (87 таблиц/901 колонок, инструменты зелёные) |
| X2 | Осиротевшая `event_dates` | ❌ | Присутствует в спеке и плоских моделях |
| — | Дублирование плоских моделей | ❌ | `app/Models` 54 файла vs 65 модульных; 17 файлов модулей импортируют плоские |

## 3. `outputs/frontend-spa-audit.md` (SPA)

| ID | Пункт | Статус | Доказательство |
|---|---|---|---|
| B2 | Восстановление корзины / cart.meta | ✅ | `useSeatHolds.ts:72,149` (cart_items.id) + `CheckoutPage.vue:255` `cart.hydrate(items, cartItemIds)` |
| B3 | Количество стоячих зон | ✅ | `CoordSeatMap.vue:55,260`, `stores/cart.ts:46-59,152-155` |
| B3b | Доменный конверт ошибок | ✅ | `lib/api.ts:137-172` + `errorEnvelope.test.ts` |
| C2 | Результат платежа «по параметру» | ✅ | `PaymentResultPage.vue:114-121,144-163` — серверный статус + честные payment_success/payment_fail |
| C3 | Статические билеты | ✅ | `TicketsPage.vue:29-41` `fetchMyTickets()`; mock удалён |
| D1 | Админ-заглушки/демо-KPI | ✅ | Все разделы на живом API (`AdminDashboardPage.vue:75-109` и др.) |
| D2 | Guard по токену; токен при неудачном входе | ✅ | `lib/auth.ts:29-33` + guard `isAdminSession()` (`router/index.ts:276-282`) |
| D3 | DOM-XSS hall-editor.html | ✅ | Файл удалён; редактор — Vue-страница |
| E1 | Metrika 404 | ✅ | `Analytics/routes/api.php:12-21` подключён (`routes/api.php:73-76`) |
| E2 | Мёртвые цели воронки | ✅ | `event_view` (`EventPage.vue:110-112`), `checkout_started` (`CheckoutPage.vue:407-410`), `payment_success/fail` (`PaymentResultPage.vue:156,163`) |
| E3 | SEO-метаданные | ✅ | Серверный SEO-контур: `EventPageController.php:207-262,704-716` |

## 4. `outputs/loyalty-crm-audit.md` (top-находки)

| Пункт | Статус | Доказательство |
|---|---|---|
| HTTP-слой промокодов | ✅ | `Pricing/routes/api.php` (6 эндпоинтов), чекаут-интеграция `CartCheckoutService.php:150-180,228-236` |
| Модель PromoCode на несуществующих колонках | ✅ | Переписана (`Orders/Models/PromoCode.php:16-55`) |
| Автосоздание аккаунта у гостя на чекауте | ✅ | `resolveBuyerId` + `CustomerAccountService::resolveForCheckout` (`CartCheckoutService.php:235-252`), запись в `carts.user_id` (per_user_limit промокодов ожил) |
| `subscribed_to_newsletter` вне схемы | ❌ | `NewsletterService.php:25`; колонки нет в бандле — SQL-ошибка при вызове (код-призрак рядом с новой лояльностью) |
| Бонусы/уровни/рефералы/розыгрыши | ✅ | Реализованы удалённой линией фаз 1–5 (схема сплитов 018–022; розыгрыши/рекомендации) |

## 5. `AUDIT-REPORT-2026-10-09.md` (ключевые разрывы)

| № | Пункт | Статус | Доказательство |
|---|---|---|---|
| 9 | Возвраты без HTTP-входа | ✅ закрыто 16.10 | Эндпоинт уже был (`Orders/routes/api.php:31`); диалог админки теперь для paid заказов вызывает именно refund (`AdminOrdersPage.vue:154-186`) — текст обещания стал правдой; расхождение пути с openapi осталось (см. договорённость ниже) |
| 9б | Текст отмены обещает возврат | ✅ закрыто 16.10 | см. выше: paid → refund-запрос, не-paid → cancel |
| 11 | `GET /tickets/{id}/pdf` | ✅ закрыто 16.10 | Роут + экшен (`Tickets/routes/api.php:74-78`, `TicketController::pdf`, `TicketPdfRenderer::render`); null-рендер = 202 AsyncJobResponse по контракту; 200 — `application/pdf` attachment |
| 13 | Чекин: устройства/offline-бандлы | ✅ закрыто 16.10 | Транспорт достроен: `GET/POST /api/v1/checkin-devices/{device}/bundles` + `GET /api/v1/offline-bundles/{bundle}` (`Checkin/routes/api.php:82-90`; `Admin\OfflineBundleController`, `OfflineBundleService` — билдер домена подключён, dedup по `bundle_hash`, device-чтение по `nbck_`-токену с отметкой `downloaded_at`) |
| 16 | SMTP/queue/cron | 🟡 | queue worker в cron `routes/console.php:35-37` + systemd unit; ресенд есть; `MAIL_HOST=` пуст в `.env.example:162` |
| 20 | OAuth (VK/Yandex/Google) | ❌ | Socialite нет в composer.json; только email-аутентификация |
| 21 | Telegram | ❌ | Каркас из 2 файлов без логики |
| 4 | Админская блокировка места | ❌ | Ни одного эндпоинта block/unblock |
| — | Сборка статики/деплой | ✅ | `docs/deploy-timeweb.md:160-174` |

## 6. `AUDIT-PAYMENTS-REFUNDS-2026-10-10.md` (R-01…R-14)

| ID | Риск | Статус | Доказательство |
|---|---|---|---|
| R-01 | Вебхук без сверки суммы (PaymentSettlement не подключён) | ✅ | `settleSucceeded` → `PaymentSettlement::settle` (`PaymentService.php:606-644`); отказ → авто-возврат `:536-538,709-742` |
| R-02 | Гонка двух initiatePayment | ✅ | lockForUpdate на заказе `:124` + фаза-3 перечек `:179-187` |
| R-03 | Гонка возвратов | ✅ | lockForUpdate на платеже (`RefundService.php:49`) |
| R-03b | Недетерминированный Idempotence-Key возврата | 🟡 | Ключ из `refund.public_id` (`:122`); таймаут-ретрай всё ещё создаёт новую строку Refund → новый ключ; GET статуса возврата нет |
| R-04 | Wildcard allowlist; HMAC по перекодированному JSON | ✅ | Wildcard фильтруется с warning (`WebhookAuthenticator.php:106-122`); HMAC по raw body (`:44`, `WebhookSignatureVerifier.php:77-84`) |
| R-05 | Оплата терминального заказа | ✅ | `assertOrderIsPayable` (`PaymentService.php:276-294`); поздний succeeded → авто-возврат |
| R-06 | Возвраты без HTTP-входа | ✅ | `POST /payments/{payment}/refund` admin (`Payments/routes/api.php:29-31`) |
| R-07 | refund.succeeded/failed не обрабатывались | ✅ | `handleRefundEvent` (`PaymentService.php:751-809`, `:500-503`) |
| R-08 | Нет массовых возвратов при отмене события | ❌ | Batch-механизма нет; отмена заперта политикой HAS_SOLD_UNITS |
| R-09 | Вызов провайдера внутри транзакции | ✅ | 3-фазный initiatePayment, провайдер вне транзакции (`:145-165`) |
| R-10 | Нет reconcile | ✅ | см. D-1 |
| R-11 | provider_event_id/audit_log | 🟡 | Заполняется для capture/refund/failure (`:562-568,723-729,802-808,959-965`); в начальной транзакции RefundService и в audit_logs — нет |
| R-12 | Прямые update мимо машин | ✅ | Машинные проверки (`:843-866`); `markAsCanceled` через репозиторий |
| R-13 | Анонимный demo-pay | ✅ | Двойной guard + 403 в проде (`PaymentController.php:321-341`) + safety-аудит |
| R-14 | waiting_for_capture → failed | 🟡 | mapStatus корректен, но в `processWebhook` нет case `payment.waiting_for_capture` (default → throw) | 

## 7. `AUDIT-INSTALLER-READINESS-2026-10-10.md`

| ID | Пункт | Статус | Доказательство |
|---|---|---|---|
| B-1 | PHP ≥ 8.1 в визарде | ✅ | `$phpRequired = '8.3'` (`InstallerController.php:337`) |
| B-2 | Демо-аккаунты admin@nabilet.local/admin123 | ✅ | Сидятся только вне прода (guard `shouldSeedDemoData`, `NabiletAdminSeeder.php:203-210`) |
| B-3 | QUEUE_CONNECTION=sync / CACHE_DRIVER | ✅ | ENV_TEMPLATE пишет `QUEUE_CONNECTION=database`, `CACHE_STORE=database` (`InstallerController.php:73-75,497-499`) |
| B-4 | Нет MAIL_* | ✅ | Блок MAIL_* + шаг SMTP в визарде (`:79-86, 501-508`) |
| W-2 | POST /install без throttle | ✅ | `throttle:5,1` (`Installer/routes/web.php:39`) |
| W-3 | Фейковая «Проверка подключения» | ✅ | Реальный AJAX `POST /install/test-database` → PDO (`install.blade.php:670-708`, `InstallerController.php:273-319`) |
| W-1/G-1/G-2 | Потеря lock → новый APP_KEY; нет backup-инструкции в docs; «успех» при warning | ❌/❌/❓ | В `docs/deploy-timeweb.md`/README бэкапов нет (модуль и cron есть); W-1/G-2 не пере-проверялись дословно |

## 8. `AUDIT-FINAL-ACCEPTANCE-2026-10-10.md` (P0)

| ID | Пункт | Статус | Доказательство |
|---|---|---|---|
| P0-1 | Бэкапы не по расписанию | ✅ | `backup:run` daily 03:00 (`routes/console.php:67-69`, `RunBackupCommand.php:24-44`) |
| P0-2 | Пароль mysqldump в ps | ✅ | `--defaults-extra-file`, temp-file 0600 (`YandexDiskBackupService.php:48-56,144`) |
| P0-3 | Drill восстановления | ❓ | Требует окружения |
| P0-4 | Feature-прогон на MySQL 8.4 | ✅ | CI job с mysql:8.4 (порт 3307) запускает `vendor/bin/phpunit` полностью (`ci.yml:419-464`) |
| P0-5 | Конкурентный стресс места | ❓ | Автотеста на конкуренцию нет; нужен нагрузочный стенд |
| P0-6 | Sandboxed YooKassa | ❓ | Нужны тестовые ключи |
| P0-7 | Деплой-валидация конфига | ✅ | `config:validate` + `ProductionSafetyAudit`: demo_mode, wildcard allowlist, https, TRUSTED_PROXIES (`ProductionSafetyAudit.php:44-82`) |

## 9. `AUDIT-PERFORMANCE-RESILIENCE-2026-10-10.md`

| ID | Пункт | Статус | Доказательство |
|---|---|---|---|
| 1.1 | Кеш публичных страниц | ❌ | `EventPageController::show()`, `StorefrontController::show()` без Cache (кроме sitemap) |
| 1.2 | Тяжёлый eager-load инвентаря; подсчёт мест в PHP | ❌ | `EventRepository.php:24`, `EventController.php:116-131`, `EventPageController.php:136` |
| 1.4 | HTTP к провайдеру без timeout | ✅ закрыто 16.10 | `YooKassaProvider.php` — все три вызова с `timeout(20)/connectTimeout(5)` |
| 1.5 | per_page без потолка | ✅ закрыто 16.10 | `EventController.php:52-57` — кламп [1..100] в единственной точке входа каталога |
| 1.5b | HoldSweeeper без LIMIT | 🟡 | Выборка без limit (`HoldSweeper.php:85-90`), но каждая сдача — своя короткая транзакция `:92-159` |
| 1.3/1.6 | Deadlocks/наблюдаемость | ❌ | см. D4-арх; метрик/алертов нет |

---

## Итог: что осталось до закрытия аудитов

**Закрыто целиком:** R-01…R-07, R-09, R-10, R-12, R-13; D-1, D-2, D-3, D-5, D-7, D-11, D-12; архитектурные D5/D1/D6/D7-арх/D10-арх/X1; фронтовый B2/B3/B3b/C2/C3/D1/D2/D3/E1/E2/E3; лояльность (включая L3, L4-фантом удалён); инсталлер B-1…B-4, W-2, W-3; приёмочные P0-1, P0-2, P0-4(контур CI), P0-7; отчёт 09.10 №9/№11/№13 и производительность 1.4/1.5.

**Открытые дефекты кода (по убыванию риска):**
1. R-08 / D-4 (арх.): нет каскада отмены события (batch refund) — только rejection-guard.
2. Производительность: кеш публичных страниц (1.1), eager-load инвентаря + подсчёт мест в PHP (1.2), deadlocks/наблюдаемость (1.3/1.6), HoldSweeper без LIMIT (1.5b, смягчён).
3. Целостность: UNIQUE(session_id, seat_id) на tickets (D2-арх — требует согласования с повторным выпуском после возврата); FK/CASCADE на orders/payments/promo (D3-арх); `EnsureIdempotency` не применён (D9-арх); гостевой токен без ротации (D-9); checkout-идемпотентность 404 на сервере (D-8 серверная половина); per_user_limit гонка (D-6b); R-11 хвосты (provider_event_id в RefundService, audit_logs); R-14 хвост (case `payment.waiting_for_capture` в processWebhook).
4. Продуктовые: OAuth, Telegram-модуль, админская блокировка места, self-service возвраты (P2-2), backup-инструкция в docs (G-1), openapi path `/admin/orders/{order}/refund` vs реализованный `/orders/{order}/refund`.

**Нужна среда/ключи (❓):** drill восстановления, стресс одного места, sandbox YooKassa, нагрузочное (P0-3/5/6, P1-1).
