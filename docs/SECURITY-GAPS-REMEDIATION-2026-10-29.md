# Устранение пробелов аудита — статус и план мультитенантности

**Дата:** 2026-10-29
**Основание:** перечень пробелов из аудита безопасности и архитектурного аудита
(`SECURITY-AUDIT-2026-10-10.md`, `docs/AUDIT-ARCHITECTURE-DATA-MODEL-2026-10-10.md`).
**Режим этого прохода:** безопасные правки в коде + план структурного изменения.
Схема БД в этом проходе не менялась.

---

## 1. Сводка по заявленным пробелам

| # | Пробел | Статус на 2026-10-29 | Где закрыто / что осталось |
|---|--------|----------------------|----------------------------|
| 1 | `organization_id` отсутствует у большинства таблиц; скоуп только через JOIN + `assertOwns()` | **Закрыт структурно (фазы 0–3)** | Все 40 таблиц класса A получили `organization_id NOT NULL` + FK на `organizations`; запись заполняет тенанта из контекста или родителя. Глобальный читающий скоуп на `Order` включён; остальные модели читаются через явные проверки §3.3 |
| 2 | Фолбэк QR-подписи на `APP_KEY` | **Закрыт** | `QrSignerFactory` — единственная точка сборки, без фолбэка. В этом проходе удалены мёртвые методы `TicketService::generateQrCode()` / `verifyQrCodeSignature()`, которые сохраняли старый фолбэк |
| 3 | Stored XSS через загрузку SVG/PDF/MP4 | **Закрыт** | `svg` убран из `MediaController::ALLOWED_TYPES`; для PDF — `Content-Disposition: attachment`, CSP `sandbox`, `nosniff` в `docker/nginx/default.conf` |
| 4 | Смена пароля/блокировка не отзывает сессии | **Закрыт** | `UserService::updateUser()` отзывает все сессии в одной транзакции при смене пароля и при статусе ≠ `active` |
| 5 | Нет rate limiting на промокодах и чекине | **Закрыт** | `RateLimitServiceProvider`: `promo-validate`, `checkin-scan`; middleware на маршрутах, тесты `RateLimitTest` |
| 6 | `idempotency_key` nullable при создании платежа | **Закрыт** | `PaymentService::normalizeIdempotencyKey()` требует непустой ключ; колонка `payments.idempotency_key` — `NOT NULL`; тесты `PaymentIdempotencyTest` |
| 7 | `TRUSTED_PROXIES=null` → доверие любым `X-Forwarded-*` | **Закрыт, с оговоркой** | `null` в `TrustProxies` оставляет список пустым; регрессионный тест `TrustedProxiesTest`. Остаточное правило: никогда не задавать `TRUSTED_PROXIES=*` |

### 1.1 Почему пункты 2–7 считаются закрытыми, а не «в процессе»

- **QR.** Рабочий путь выпуска билета идёт через `QrSignerFactory::make()`; при пустом
  `nabilet.ticket.qr_secret` бросается `QR_SECRET_NOT_CONFIGURED` (HTTP 500), а не
  подпись `APP_KEY`. `TicketScanService::verifyQr()` использует ту же фабрику.
  Старые методы в `TicketService` не вызывались ни из контроллеров, ни из тестов, но
  оставались готовым фолбэком — поэтому удалены. Новый статический сторож
  `tests/Unit/TicketQrSigningKeyTest.php` не даст вернуть `config('app.key')` или
  второе чтение `qr_secret`.
- **Загрузки.** `ALLOWED_TYPES` больше не содержит `svg`; второй вход
  (`storeRegistered()`) выводит `in:` из той же карты, а не принимает произвольный
  `mime_type`. PDF отдаётся как вложение с `CSP: default-src 'none'; sandbox`.
  Остаточное условие: заголовки заданы в `docker/nginx/default.conf`, поэтому при
  деплое без этого nginx (или за другим прокси) их нужно продублировать.
- **Сессии.** `UserService::updateUser()` вычисляет `$revokeSessions` до транзакции:
  смена пароля, перевод в `banned`/`inactive` → `SessionIssuer::revokeAllFor()` в той
  же транзакции, что и UPDATE. `UserSessionRevocationTest` проверяет старый Bearer
  после смены пароля и после бана.
- **Rate limiting.** Оба лимитера зарегистрированы в провайдере, который реально
  бутится (`bootstrap/providers.php`), а не только объявлен. `RateLimitTest`
  проверяет и наличие middleware на маршрутах, и конфигурацию лимитеров.
- **Платежи.** HTTP-правило оставляет `nullable` только потому, что ключ может
  прийти заголовком `Idempotency-Key`; сервис — последняя граница — отклоняет пустое
  значение. Повтор с тем же ключом возвращает существующий платёж (HTTP 200), новое
  создание — 201.
- **Прокси.** В Laravel 13 `TrustProxies::handle()` при `at: null` оставляет список
  доверенных прокси пустым. Это закреплено тестом, чтобы будущая правка
  `at: ... : '*'` не прошла незамеченной.

---

## 2. Что изменено в этом проходе

| Файл | Изменение |
|------|-----------|
| `app/Modules/Tickets/Services/TicketService.php` | Удалены неиспользуемые `generateQrCode()` и `verifyQrCodeSignature()` — последние места с фолбэком на `APP_KEY`; удалены ставшие ненужными импорты `HmacSignature` и `SignedPayload` |
| `tests/Unit/TicketQrSigningKeyTest.php` | Новый статический сторож: в `app/Modules/Tickets` запрещены `config('app.key')`, `env('APP_KEY')`, `config('tickets.qr_secret')`; `nabilet.ticket.qr_secret` читается ровно в одном файле |
| `app/Core/Tenancy/OrganizationContext.php` | Добавлены `allows()` и `assertCanAccess()`; `assertOwns()` переведён на них и при отсутствии контекста даёт 404, а не 500 |
| `app/Core/Http/Middleware/ResolveOrganizationContext.php` | Контекст очищается в начале каждого запроса: значение прошлого запроса не переживает своего пользователя |
| `app/Modules/Orders/Http/Controllers/OrderController.php` | Staff-ветки `index/store/show/cancel/refund/resendTicket` ограничены активной организацией; чужой `organization_id` в query — 422 |
| `app/Modules/Payments/Http/Controllers/PaymentController.php` | `index`, `mayPay`, `assertCanSee` проверяют организацию заказа; владелец/гость сохраняют доступ |
| `app/Modules/Tickets/Http/Controllers/TicketController.php` | `index` фильтрует билеты staff по `order.organization_id`; `assertCanSee` не пропускает staff к чужой организации |
| `app/Modules/Orders/Services/OrderService.php` | `createOrder()` отвергает позиции склада из события/сеанса другой организации |
| `app/Modules/Tickets/Services/TicketScanService.php` | `scan/canCheckin/scanFromBundle` принимают `organizationId`; билет (через `orders`) и сеанс (через `events`) обязаны принадлежать организации; идемпотентный повтор тоже проверяется |
| `app/Modules/Checkin/Http/Controllers/CheckerController.php` | `validate/use/sync` передают `organization_id` устройства |
| `app/Modules/Tickets/Http/Controllers/CheckinController.php` | Staff-чекин берёт организацию из `OrganizationContext`/`UserOrganization`; чужой `device_id` — 404 |
| `app/Modules/Checkin/Http/Controllers/Admin/CheckinDeviceController.php` | Список скоуплен организацией, чужой query-фильтр — 422; выдача в чужую организацию и PATCH чужого устройства — 404 |
| `app/Modules/Checkin/Http/Controllers/Admin/CheckinScanReportController.php` | Журнал сканов ограничен билетами заказов своей организации |
| `app/Modules/Checkin/Http/Controllers/Admin/OfflineBundleController.php` | Устройство, событие и бандл проверяются по организации; сеанс обязан принадлежать выбранному событию |
| `tests/Support/StaffAuth.php` | Создаёт и `user_roles`, и `user_organization`, чтобы контекст заполнялся в feature-тестах |
| `tests/Support/SellableSeatFixtures.php` | `seedSellableSeat()` умеет переиспользовать организацию — тест «другое событие того же арендатора» больше не проверяет межтенантный отказ по ошибке |
| `tests/Feature/Checkin/CheckerHttpLayerTest.php` | Новые тесты: билет чужой организации → `unknown_ticket` без записи; админ org A не видит/не отзывает устройство org B |
| `app/Modules/Cart/Services/CartCheckoutService.php` | Убран фолбэк организации `?? 1`: организация заказа обязана прийти из `events.organization_id`/`venues.organization_id` (NOT NULL), иначе оформление падает с `CART_ORGANIZATION_UNRESOLVED`, а не молча приписывает заказ арендатору №1 |
| `tests/Unit/CartOrganizationResolutionTest.php` | Новый статический сторож: в `app/Modules/Cart` запрещён числовой фолбэк рядом с `organization_id` (`?? N` / `|| N`) |
| `app/Modules/Tickets/Repositories/TicketRepository.php` | Фаза 0: `find()`/`findByPublicId()` требуют `int $organizationId` — необязательный скоуп убран; добавлены явные системные `findAny()`/`findAnyByPublicId()` |
| `app/Modules/Payments/Repositories/PaymentRepository.php` | Фаза 0: `find()`/`findByPublicId()` требуют `int $organizationId`; добавлены `findAny()`/`findAnyByPublicId()` |
| `app/Modules/Tickets/Services/TicketService.php` | `findTicket()`/`findByPublicId()` требуют организацию; добавлены `findTicketAny()`/`findAnyByPublicId()` |
| `app/Modules/Payments/Services/PaymentService.php` | `findPayment()`/`findByPublicId()` требуют организацию; добавлены `findPaymentAny()`/`findAnyByPublicId()` |
| `app/Modules/Orders/Repositories/OrderRepository.php` | Фаза 0: `find()`/`findByPublicId()` требуют `int $organizationId`; добавлены `findAny()`/`findAnyByPublicId()` |
| `app/Modules/Orders/Services/OrderService.php` | `findOrder()`/`findByPublicId()` требуют организацию; добавлены `findOrderAny()`/`findAnyByPublicId()` |
| `tests/Unit/TenantRepositoryScopeGuardTest.php` | Новый статический сторож: в репозиториях билетов/платежей/заказов запрещён `?int $organizationId` и обязателен `findAny*` |
| `phpstan-baseline.neon` | Обновлены счётчики паттернов `Ticket::where()`/`Payment::where()`/`Order::where()` под новые вызовы |
| `database/migrations/2026_10_29_000100_add_organization_id_to_tenant_tables.php` | Фаза 1: nullable `organization_id` + индекс для 40 таблиц класса A (ключи сверены со спекой `nabilet_core_spec/migrations.sql`). **Применена к dev-БД** (`php artisan migrate`), все 40 колонок на месте |
| `app/Modules/System/Console/TenancyBackfillOrganizationCommand.php` | Команда `tenancy:backfill-organization`: идемпотентный бэкфилл из родителя; `--dry-run` (UPDATE в транзакции с откатом), `--table`, отчёт о нераспределённых NULL |
| `bootstrap/app.php` | Команда зарегистрирована в `withCommands` |
| `tests/Unit/TenancyPhase1ConsistencyTest.php` | Сторож: набор таблиц миграции обязан совпадать с набором шагов команды бэкфилла |
| `app/Modules/Core/Tenancy/TenantScoped.php` | Фаза 2: трейт `TenantScoped` — авто-заполнение `organization_id` при `creating` из контекста, отклонение несовпадения, опциональный глобальный читающий скоуп (fail-closed, off по умолчанию) |
| `app/Modules/Orders/Models/Order.php`, `app/Modules/Payments/Models/Payment.php`, `app/Modules/Tickets/Models/Ticket.php` | Фаза 2: применён write-side `TenantScoped` (глобальный скоуп выключен до аудита системных путей чтения) |
| `tests/Feature/Core/TenantScopedWriteTest.php` | Явная проверка write-side: заполнение org из контекста, отклонение чужой org, создание без контекста |
| `tests/Feature/Core/TenantScopedReadTest.php` | Проверка читающей стороны глобального скоупа: с контекстом фильтрует; без контекста бросает; внутри `withoutScope()` видно всё |
| `app/Modules/Orders/Services/StaleOrderExpirer.php`, `OrderReminderSweeper.php`, `UnpaidOrderReminderSweeper.php`, `EventOrderRefunder.php` | Фоновые сервисы заказов обёрнуты в `OrganizationContext::withoutScope()` — первый блок миграции системного слоя (подготовка к включению скоупа) |
| `database/migrations/2026_11_02_000100_enforce_organization_id_on_tenant_tables.php` | **Фаза 3**: для тех же 40 таблиц `organization_id` стал `NOT NULL` и получил FK `fk_<table>_org` на `organizations(id)` с `ON DELETE CASCADE`. Перед изменением миграция считает NULL и падает с требованием `tenancy:backfill-organization`. **Применена к dev-БД** |
| `app/Modules/Core/Tenancy/TenantResolver.php` | Карта `таблица → [внешний ключ, родитель]`: организация выводится из родителя, когда контекста запроса нет (вебхук, гостевой checkout, консоль, очередь). Рекурсивный подъём до `organization_id`, защита от циклов |
| `app/Modules/Core/Tenancy/TenantScoped.php` | Фаза 3, запись: `TenantScoped` добавлен всем 31 модели класса A. Приоритет — контекст, затем родитель; конфликт контекста и родителя или явно чужой `organization_id` — `NotFoundError` (fail-closed) |
| `app/Modules/Inventory/Repositories/InventoryItemRepository.php` | `bulkCreate()` — это `Model::insert()`, события модели не срабатывают: строка без `organization_id` теперь отвергается, а не пишется «ничьей» |
| `app/Modules/Events/Console/SyncArtistsCommand.php` | `artist_event` через `sync()` получает `organization_id` из события: пивот пишется конструктором запросов, в обход событий модели |
| `tests/Feature/Core/TenantResolverWriteTest.php` | Наследование организации от родителя, отказ при чужом контексте/явном чужом `organization_id`, отсутствие цепочки у глобальных таблиц |
| `app/Modules/Payments/Models/Payment.php` | Фаза 2, чтение: глобальный скоуп включён на `Payment` — вторая модель после `Order`. Системные пути уже обёрнуты в `withoutScope()`: `initiatePayment`/`createPayment`/`processWebhook`, `reconcile` (`stuckPayments`), `confirmDemoPayment` (демо-шлюз читает платёж без организации) |
| `app/Modules/Orders/Http/Controllers/OrderController.php`, `app/Modules/Orders/Services/OrderService.php` | Детей авторизованного заказа читаем через родителя: `show` грузит `payments` в `withoutScope()` (покупатель без staff-организации), `applyPayment` считает сумму платежей заказа вне скоупа (вебхук и тесты) |
| `tests/Feature/Core/TenantScopedReadTest.php` | Нативный скоуп `Payment`: без контекста отказ, с контекстом фильтр, в bypass видны все |
| `app/Modules/Tickets/Models/Ticket.php` | Фаза 2, чтение: глобальный скоуп включён на `Ticket` — третья модель после `Order` и `Payment`. Route-binding резолвится в bypass, доступ решается после (`assertCanSee`, организация устройства). Системные пути со своей защитой арендатора живут в bypass: выпуск билетов по оплаченному заказу (`TicketService::issueTicketsForOrder`, дети уже найденного вебхуком заказа), скан билета (`TicketScanService::scan`/`scanFromBundle` — арендатор проверяется `belongsToOrganization` по токену устройства), офлайн-бандл чекера (`OfflineBundleService::issue`), синхронизация чекера (`TicketLocator`), платёжное уведомление (`SendOrderNotificationJob` уже читал всё внутри bypass) |
| `app/Modules/Tickets/Http/Controllers/TicketController.php`, `app/Modules/Tickets/Http/Controllers/CheckinController.php`, `app/Modules/Checkin/Http/Controllers/CheckerController.php` | Списки и верификации с УЖЕ явной авторизацией: `index` фильтрует по владельцу/организации сам — paginate в bypass; чекин верифицирует билет по токену устройства/членству сотрудника — чтение билета в bypass |
| `app/Modules/Inventory/Http/Controllers/InventoryController.php` | Публичная витрина доступности: счётчик проданных билетов сеанса читается в bypass — арендатор определяется сеансом, у гостя организации в контексте нет |
| `tests/Feature/Core/TenantScopedReadTest.php` | Нативный скоуп `Ticket`: без контекста отказ, с контекстом фильтр, в bypass видны все (FK-цепочка собирается фикстурой) |
| `tests/Feature/Events/EventCancellationCascadeTest.php`, `tests/Feature/Notifications/OrderStatusNotificationTest.php`, `tests/Feature/Orders/OrderWritePathTest.php` | Тест-читатели билетов/платежей без HTTP-контекста переведены на `withoutScope()` — тот же режим, в котором живут системные пути |
| `app/Modules/Payments/Models/Refund.php` | Фаза 2, чтение: глобальный скоуп включён на `Refund` — четвёртая модель после `Order`, `Payment`, `Ticket`. Все существующие чтения уже в bypass: сверка вебхука (`processWebhook`), остаток при инициации возврата (`RefundService::refund`), дети авторизованного платежа. Route-binding не нужен — возврат не адресуется в маршрутах |
| `app/Modules/Payments/Models/Payment.php` | Дополнение: `resolveRouteBinding()` в bypass (паттерн `Order`/`Ticket`) — `GET /payments/{id}` и `POST /payments/{id}/refund` резолвят платёж до проверки доступа; залогиненный покупатель без staff-организации раньше падал бы 500 ещё на резолве |
| `app/Modules/Payments/Http/Controllers/PaymentController.php` | Дети авторизованного платежа читаются через родителя в bypass: `assertCanSee` (ленивый `$payment->order` упал бы у покупателя на скоупированном `Order`), `show` (`order/transactions/refunds`), `refund` (`refunds/transactions`) |
| `tests/Feature/Core/TenantScopedReadTest.php` | Нативный скоуп `Refund`: без контекста отказ, с контекстом фильтр, в bypass видны все |
| `app/Modules/Tickets/Models/TicketScan.php` | Фаза 2, чтение: глобальный скоуп включён на `TicketScan` — пятая модель после `Order`, `Payment`, `Ticket`, `Refund`. Скан/анализ идут под токеном устройства в bypass; eager-load `scans` — дети авторизованных билетов; журнал сканов читается под контекстом сотрудника со своим фильтром по организации заказа. Route-binding не нужен — скан не адресуется в маршрутах |
| `app/Modules/Tickets/Http/Controllers/TicketController.php` | Дополнение: `show` и `history` читают `scans` в bypass — дети уже авторизованного билета |
| `app/Modules/Tickets/Models/CheckinDevice.php` | Фаза 2 + запись: подключён трейт `TenantScoped` (модель его не использовала вовсе — единственный писатель `DeviceTokenIssuer::issue` передавал организацию сам, без защиты от записи чужому тенанту) и включён глобальный читающий скоуп — шестая модель после `Order`, `Payment`, `Ticket`, `Refund`, `TicketScan`. Резолв по токену чекера — capability — идёт в bypass, активный арендатор ставится из найденного устройства. Route-binding не нужен — устройство резолвится `IdentifierLookup` (сам в bypass) по строковому параметру |
| `app/Modules/Checkin/Http/Middleware/AuthenticateChecker.php` | Lookup по `device_token_hash` в bypass: организации в контексте ещё нет — она ставится ИЗ найденного устройства строкой ниже (без изменений) |
| `app/Modules/Checkin/Http/Controllers/Admin/OfflineBundleController.php` | `deviceIdOrStaff`: lookup устройства по токену в bypass; после нахождения контекст ставится из устройства — иначе `touch` (телеметрия `last_seen`) молча терялась бы на скоупе без контекста |
| `app/Modules/Tickets/Models/OfflineBundle.php` | Фаза 2 + запись: подключён трейт `TenantScoped` (модель его не использовала вовсе — единственный писатель `OfflineBundleService::issue` передавал организацию сам) и включён глобальный читающий скоуп — седьмая модель после `Order`, `Payment`, `Ticket`, `Refund`, `TicketScan`, `CheckinDevice`. Идемпотентный повтор выпуска ищет бандл по хешу того же устройства (хеш device-scoped) под контекстом — не в bypass |
| `app/Modules/Payments/Models/PaymentTransaction.php` | Фаза 2, чтение: глобальный скоуп включён на `PaymentTransaction` — восьмая модель. Запись уже была защищена трейтом (организация из платежа через `TenantResolver`). Чтения: дети авторизованного платежа (show/refund/выпуск) — в bypass; вебхуки — в bypass; список покупателя — bypass вокруг детей собственных платежей. Route-binding не нужен |
| `app/Modules/Payments/Http/Controllers/PaymentController.php` | Дополнение: `index` покупателя читает транзакции детей СВОИХ платежей (фильтр по `user_id`) в bypass; список сотрудника идёт под его организацией — скоуп лишь повторяет фильтр |
| `app/Modules/Orders/Models/OrderItem.php` | Фаза 2, чтение: глобальный скоуп включён на `OrderItem` — девятая модель. Запись уже защищена трейтом (организация из заказа). Чтения — дети авторизованных родителей: eager `orderItem` у билетов (bypass с шага `Ticket`), позиции заказов в списке/создании/отмене — обход children-only путей гостя. Route-binding не нужен |
| `app/Modules/Orders/Services/OrderService.php` | `createOrder`: ответ с `items.inventoryItem` — в bypass (арендатор заказа проверен по сеансу позиции склада; прямой чекаут гостя без контекста). `cancelOrder`: цикл по позициям в bypass — доступ решён роутом, тесты зовут сервис в обход HTTP |
| `app/Modules/Orders/Http/Controllers/OrderController.php` | `index`/`mine`: список покупателя в bypass (позиции детей СВОИХ заказов по `user_id`), сотрудника — под организацией; `store`: ответ созданного заказа (`fresh()->load('items')`) в bypass. `cancel`/`refund` идут под контекстом сотрудника — обёрток не требуют |
| `app/Modules/Orders/Models/SeatHold.php` | Фаза 2, чтение: глобальный скоуп включён на `SeatHold` — десятая модель. Запись уже защищена трейтом (организация из корзины через `TenantResolver`), и она же отсекает запись «в чужую корзину». Чтение живёт по capability токена корзины, а не по организации HTTP-запроса: держка — ребёнок корзины гостя, идентифицированной `cart_token`. Sweeper'ы (`HoldSweeper`, `StaleOrderExpirer`) ходят сырым `DB::table` — их Eloquent-скоуп не касается |
| `app/Modules/Cart/Services/CartItemService.php` | Держки — дети корзины (capability-токен): снятие держек при удалении позиции, продление (`extendHold`), освобождение корзины (`releaseCartInventory`), подбор держки при повторном добавлении (`growHold`) — в bypass как children-only пути гостя |
| `app/Modules/Orders/Services/OrderService.php` | Дополнение: освобождение активных держек при отмене заказа — в bypass (сервис зовут и в обход HTTP: тесты, каскад отмены события) |
| `tests/Feature/InventoryRepositoryContractTest.php` | Контракт-тест дёргает репозиторий напрямую (`getHeldCount` — whereHas по связи `holds`): тест-аналог системного пути, обёрнут в bypass; в проде метод пока не вызывается, fail-closed остаётся |
| `app/Modules/Cart/Models/CartItem.php` | Фаза 2, чтение: глобальный скоуп включён на `CartItem` — одиннадцатая модель. Запись уже защищена трейтом (организация из корзины через `TenantResolver`) — она же отсекает запись «в чужую корзину». Чтение живёт по capability токена корзины (`cart_token`), а не по организации HTTP-запроса: позиция — ребёнок корзины гостя. Позиция не адресуется в маршрутах — route-binding не нужен |
| `app/Modules/Cart/Services/CartItemService.php` | Дополнение (позиции): поиск существующей позиции при добавлении, позиция при удалении, `exists()` при продлении, пересчёт суммы корзины — в bypass как children-only пути гостя |
| `app/Modules/Cart/Services/CartService.php` | Полная очистка корзины по токену: удаление позиций — в bypass (гость без контекста) |
| `app/Modules/Cart/Services/CartCheckoutService.php` | Чекаут: обе точки поиска корзины с eager `items.inventoryItem` (по токену и по `public_id`) — в bypass; дальше циклы и сводка читают кэшированные связи, запросов не порождают |
| `app/Modules/Cart/Http/Controllers/CartController.php` | Витрина: показ/создание/добавление (eager `items` ×5) — в bypass; владелец корзины объявлен фильтром по `cart_token`/`user_id` внутри запроса |
| `app/Modules/Embed/Http/Controllers/EmbedController.php` | Дополнение: `createCart` грузит `items.inventoryItem` в bypass — витрина-эмбед отвечает гостю без контекста; реальный доступ решает `EmbedAccessService` через сессию → событие |
| `app/Modules/Inventory/Services/InventoryService.php` | Пересчёт цен открытых корзин — системный путь (переоценка сеанса): выборка позиций по ЯВНОМУ списку склада и суммы по корзинам — в bypass |
| `phpstan-baseline.neon` | Ratchet почищен (шаг вскрыл устаревшие записи): удалены `modelKeys`-на-CartItemService, `lockOwnedByPublicId`/`convert`-на-CartCheckoutService, `fresh()`-на-CartItemService — типы стали mixed после bypass-обёрток; счётчик `update()`-на-stdClass 6→4 |
| `tests/Feature/Core/TenantScopedReadTest.php` | Нативный скоуп `CartItem`: без контекста отказ, с контекстом фильтр, в bypass видны все |
| `app/Modules/Cart/Models/Cart.php` | Фаза 2, чтение: глобальный скоуп включён на `Cart` — двенадцатая модель. Запись защищена трейтом (организация из сеанса через `TenantResolver`, фаза 3 — NOT NULL). Чтение живёт по capability токена корзины (`cart_token`), а не по организации HTTP-запроса: корзина гостя идентифицируется токеном, владелец аккаунта — `user_id` |
| `app/Modules/Cart/Services/CartService.php` | `getOrCreateCart` и `clearCart`: поиск активной корзины по сеансу+токену — в bypass (гость без контекста; создание корзины идёт мимо скоупа — организация выводится из сеанса) |
| `app/Modules/Cart/Services/CartItemService.php` | Дополнение (корзины): поиск корзины при удалении позиции и при продлении холда — в bypass |
| `app/Modules/Cart/Http/Controllers/CartController.php` | Дополнение: `ownedCart` (общая точка владения для `showByPublicId`/`addItemTo`/`removeItemFrom`) — в bypass; чужая корзина остаётся 404 по фильтру токен/`user_id` |
| `app/Modules/Orders/Http/Controllers/OrderController.php` | Дополнение: проверка права оплаты гостем (`mayPay` — заказ уже авторизован, владение решает сравнение токена корзины) — в bypass |
| `app/Modules/Payments/Http/Controllers/PaymentController.php` | Дополнение: `holdsRequestAccess` — тот же токен-capability путь оплаты — в bypass |
| `app/Modules/Tickets/Http/Controllers/TicketController.php` | Дополнение: «мои билеты» гостя — id корзин по токену (capability, `strlen >= 32`) — в bypass, слияние с order-списком покупателя |
| `app/Modules/Pricing/Http/Controllers/PromoCodeController.php` | `resolveCart`: обе ветки резолва корзины (по токену и по id из запроса) — в bypass; гостевой путь проверки промокода без контекста |
| `app/Modules/Embed/Http/Controllers/EmbedController.php` | Дополнение: перечитывание корзины после добавления позиции, `callerHoldsCart` и чтение созданного заказа в ответе `createOrder` — в bypass. Чтение заказа чинит латентный 500: ветка жила без контекста с шага `Order` (embed-тесты её не достигали — все негативные) |
| `app/Modules/Embed/Services/EmbedAccessService.php` | `findByReference` — в bypass: lookup по `public_id`/id — НЕ решение о доступе (следом идут явные проверки организации события и токена корзины). Резолвер служит скоупнутым `Order`/`Cart`, а embed-запросы контекста не имеют |
| `app/Modules/Inventory/Services/InventoryService.php` | Дополнение (/cart): выборка активных корзин при переоценке цен сеанса — системный путь, контекст вызывающего не сужает выборку |
| `phpstan-baseline.neon` | Ratchet шага `Cart`: запись `items()`-на-`stdClass` удалена (сайт ушёл в mixed), счётчик `update()`-на-`stdClass` на `CartItemService` 4→3 |
| `tests/Feature/Core/TenantScopedReadTest.php` | Нативный скоуп `Cart`: без контекста отказ, с контекстом фильтр, в bypass видны все |
| `app/Modules/Orders/Models/PromoCodeRedemption.php` | Фаза 2, чтение: глобальный скоуп включён на `PromoCodeRedemption` — тринадцатая модель. Запись защищена трейтом (организация из промокода через `TenantResolver`). Потребитель чтений один — `PromoCodeService` в гостевом чекауте; расход не адресуется в маршрутах — route-binding не нужен |
| `app/Modules/Pricing/Services/PromoCodeService.php` | Три точки чтения расхода — в bypass: re-check `per_user_limit` под локом при `redeem`, идемпотентная чистка расхода перед вставкой, `userRedemptions()` (limит из контекста оценки `buildContext`) — чекаут живёт без тенант-контекста |
| `tests/Feature/Core/TenantScopedReadTest.php` | Нативный скоуп `OrderItem`: без контекста отказ, с контекстом фильтр, в bypass видны все |
| `tests/Feature/Core/TenantScopedReadTest.php` | Нативный скоуп `SeatHold`: без контекста отказ, с контекстом фильтр, в bypass видны все |
| `tests/Feature/Core/TenantScopedReadTest.php` | Нативный скоуп `PaymentTransaction`: без контекста отказ, с контекстом фильтр, в bypass видны все |
| `app/Modules/Checkin/Http/Controllers/Admin/OfflineBundleController.php` | `show`: бандл резолвится в bypass ДО того, как токен чекера поставит арендатора в контекст (иначе у устройства был бы 500 вместо 404); доступ по-прежнему решают `assertCanAccess` (сотрудник) и сравнение организации/устройства (чекер) |
| `app/Modules/Core/Tenancy/TenantResolver.php` | Карта родителей дополнена: `offline_bundles` → `checkin_device_id` → `checkin_devices` (устройства — конец цепочки, у них своя `organization_id`) — системная запись бандла без контекста выводит тенанта из родителя |
| `tests/Feature/Core/TenantScopedReadTest.php` | Нативный скоуп `OfflineBundle`: без контекста отказ, с контекстом фильтр, в bypass видны все |
| `tests/Feature/Core/TenantScopedReadTest.php` | Нативный скоуп `CheckinDevice`: без контекста отказ, с контекстом фильтр, в bypass видны все |
| `tests/Unit/TenancyPhase1ConsistencyTest.php` | Сторож расширен: список таблиц фазы 3 обязан совпадать со списком фазы 1 |
| `tests/Support/SellableSeatFixtures.php` и фикстуры feature-тестов | Все прямые вставки строк класса A заполняют `organization_id` из уже известного родителя |
| `docs/SECURITY-GAPS-REMEDIATION-2026-10-29.md` | Этот документ |

Проверки после фаз 0–3: `php -l` по изменённым файлам — без ошибок; unit-набор
`php tests/run.php` — 0 падений (включая `TenancyPhase1ConsistencyTest`,
`TenantRepositoryScopeGuardTest`, `CartOrganizationResolutionTest`); feature/`phpunit`
— `OK (1205 tests, 4911 assertions)` на живой MySQL `127.0.0.1:3307`;
`php vendor/bin/phpstan analyse` — `No errors`. Миграции фаз 1 и 3 применены к
dev-БД: 40 колонок `organization_id NOT NULL`, 40 FK `fk_<table>_org`.

### 2.1 Что осталось открытым после правок

| Риск | Почему не закрыт в этом проходе |
|------|--------------------------------|
| `organization_id` у 70 таблиц | **Закрыт структурно.** Фазы 0–3 завершены: 40 таблиц класса A — `NOT NULL` + FK; запись наполняет тенанта из контекста/родителя; глобальный читающий скоуп включён на `Order`, `Payment`, `Ticket`, `Refund`, `TicketScan`, `CheckinDevice`, `OfflineBundle`, `PaymentTransaction`, `OrderItem`, `SeatHold`, `CartItem`, `Cart` и `PromoCodeRedemption`, остальные модели — точечно по мере аудита их системных путей чтения (§3.3) |
| Списки/отчёты вне Orders/Payments/Tickets/Checkin | Проверены только пути, где аудит нашёл живую утечку; остальные закрываются общим `TenantScoped` в фазе 2 |
| `PaymentController::store` и `OrderController::resendTicket` вне `auth:api` | Staff-ветки там не единственная защита; полноценно закрывается контекстом + схемой в фазе 2 |


---

## 3. Мультитенантность: структурный план

### 3.1 Текущее состояние

В эталонной схеме `nabilet_core_spec/migrations.sql` — **88 таблиц**. Колонка
`organization_id` есть у **18**:

```
user_organization · events · venues · orders · ticket_templates · checkin_devices ·
pages · ab_experiments · embed_domains · webhooks · api_keys · audit_logs ·
promo_codes · media_assets · offline_bundles · user_roles · storefront_settings ·
order_reminders
```

У **70** таблиц колонки нет. Часть из них — глобальные справочники и identity
(`users`, `roles`, `permissions`, `organizations`), но у самых чувствительных
сущностей колонки тоже нет:

```
carts · cart_items · seat_holds · order_items · payments · payment_transactions ·
refunds · tickets · ticket_scans · sessions · inventory_items
```

Сейчас изоляция держится на том, что каждый репозиторий вручную поднимается по
цепочке `tickets → orders → organization_id` или `payments → orders`. Это работает
ровно до первого запроса, где JOIN забыли. `assertOwns()` помогает только там, где
его вызвали, и не подставляет условие в SQL.

Дополнительный риск — необязательный параметр скоупа:

```php
TicketRepository::find(int $id, ?int $organizationId = null)   // null → любая организация
PaymentRepository::find(int $id, ?int $organizationId = null)  // null → любая организация
```

Такая сигнатура делает «забыли передать организацию» валидным вызовом. Это нужно
убрать независимо от схемы.

### 3.2 Целевая модель

Выбираем **вариант 1 из `docs/ARCHITECTURE.md` §6**: денормализовать
`organization_id` в тенант-таблицы и заполнять его при создании. Это дороже, чем
обязательный JOIN в репозиториях, но даёт структурную гарантию: забытый JOIN
превращается в забытое поле, а не в утечку.

Три класса таблиц:

#### A. Тенант-таблицы: `organization_id NOT NULL`

Строка без организации не имеет смысла. Колонка добавляется, бэкфиллится и затем
объявляется `NOT NULL`.

| Таблица | Источник бэкфилла |
|---|---|
| `sessions` | `events.organization_id` через `sessions.event_id` |
| `inventory_items` | `sessions.organization_id` |
| `carts` | `sessions.organization_id`; если `session_id` NULL — единственное членство `user_organization`; иначе ручной разбор |
| `cart_items` | `carts.organization_id` |
| `seat_holds` | `carts.organization_id`, иначе `sessions.organization_id` |
| `order_items` | `orders.organization_id` |
| `payments` | `orders.organization_id` |
| `payment_transactions` | `payments.organization_id` |
| `refunds` | `orders.organization_id` |
| `tickets` | `orders.organization_id` |
| `ticket_scans` | `tickets.organization_id`, иначе `sessions.organization_id` |
| `halls` | `venues.organization_id` |
| `hall_schema_versions` | `halls.organization_id` |
| `sectors` | `hall_schema_versions.organization_id` |
| `hall_rows` | `sectors.organization_id` |
| `seats` | `hall_rows.organization_id` |
| `hall_tables` | `sectors.organization_id` |
| `standing_zones` | `sectors.organization_id` |
| `event_translations` | `events.organization_id` |
| `venue_translations` | `venues.organization_id` |
| `page_translations` | `pages.organization_id` |
| `promo_code_redemptions` | `promo_codes.organization_id` |
| `event_dates` | `events.organization_id` |
| `event_speakers` | `events.organization_id` |
| `event_sponsors` | `events.organization_id` |
| `event_faqs` | `events.organization_id` |
| `event_artists` | `events.organization_id` |
| `event_schedule_items` | `events.organization_id` |
| `artist_event` | `events.organization_id` (сам `artists` остаётся глобальным каталогом) |
| `ab_variants` | `ab_experiments.organization_id` |
| `ab_assignments` | `ab_experiments.organization_id` |
| `ab_metrics` | `ab_experiments.organization_id` |
| `webhook_deliveries` | `webhooks.organization_id` |
| `event_favorites` | `events.organization_id` |
| `event_follows` | `events.organization_id` |
| `follow_notifications` | `event_id` → `events.organization_id` |
| `raffles` | нет родителя: из `raffle_entries → orders.organization_id`; при отсутствии записей — ручное сопоставление с организатором |
| `raffle_prizes` | `raffles.organization_id` |
| `raffle_entries` | `orders.organization_id`, иначе `raffles.organization_id` |
| `raffle_winners` | `raffles.organization_id` |

#### B. Смешанные таблицы: `organization_id NULL` допустим

Строка может быть глобальной (системной) либо принадлежать организации. Политика:
`NULL` означает «глобальная запись», а не «скоуп не применён». Читающий код обязан
явно выбирать `organization_id = :id OR organization_id IS NULL` — как уже сделано
в `MediaController::index()`.

| Таблица | Почему NULL допустим |
|---|---|
| `media_links` | глобальный медиа-актив может быть привязан к организации позже |
| `seo_meta` | полиморфная: `entity_type`/`entity_id`; у глобальной страницы организации нет |
| `redirects` | общесистемные SEO-редиректы |
| `notifications` | уведомление пользователя вне контекста организации |
| `analytics_events` | анонимное событие витрины без `event_id` |
| `heatmap_events` | то же |
| `idempotency_keys` | системные ключи; тенант-ключи получают `organization_id` |
| `newsletter_subscribers` | глобальная подписка на маркетинг |
| `bonus_accounts`, `bonus_transactions`, `referral_codes`, `referrals` | лояльность сейчас общая для платформы; при переходе на «лояльность организации» — перевести в класс A |
| `consents`, `privacy_requests` | права субъекта данных действуют поверх организаций |
| `settings` | глобальные настройки и `scope`-ключи |

#### C. Глобальные таблицы: колонку не добавляем

```
organizations · users · roles · permissions · role_permissions · user_sessions ·
login_logs · event_categories · notification_templates · webhook_events ·
ip_rules · modules · metrika_settings · settings · artists · loyalty_levels
```

`users` — глобальная identity: один человек может быть покупателем и сотрудником
нескольких организаций. Членство уже вынесено в `user_organization`; добавлять
`users.organization_id` нельзя — это вернуло бы «пользователь принадлежит ровно
одной организации».

### 3.3 Фазы внедрения

#### Фаза 0. Инвентаризация и сторож (до миграций)

1. Зафиксировать в CI список таблиц класса A и источник бэкфилла (этот документ +
   машинно-читаемый массив в тесте или `tools/verify-tenancy.php`).
2. Добавить тест: каждая таблица класса A имеет `organization_id` в
   `migrations.sql`/миграциях; ни одна таблица класса C его не имеет.
3. Убрать необязательный скоуп из репозиториев **до** появления колонки:
   `find(int $id, int $organizationId)`; для системных вызовов — отдельный
   `findAny(int $id)` с явным именем и `@internal`.
4. Добавить кросс-тенантные feature-тесты на текущую схему: пользователь org A не
   видит заказ/платёж/билет org B (404, не 403).

#### Фаза 1. Колонки и бэкфилл (nullable)

Одна миграция на класс A:

```php
Schema::table('tickets', function (Blueprint $table): void {
    $table->unsignedBigInteger('organization_id')->nullable()->after('id');
    $table->index(['organization_id', 'status'], 'idx_tickets_org_status');
    $table->index(['organization_id', 'order_id'], 'idx_tickets_org_order');
});
```

Затем идемпотентная команда `tenancy:backfill-organization` (chunked, `--dry-run`,
счётчики по каждой таблице). Примеры SQL:

```sql
UPDATE sessions s
JOIN events e ON e.id = s.event_id
SET s.organization_id = e.organization_id
WHERE s.organization_id IS NULL;

UPDATE inventory_items i
JOIN sessions s ON s.id = i.session_id
SET i.organization_id = s.organization_id
WHERE i.organization_id IS NULL;

UPDATE carts c
JOIN sessions s ON s.id = c.session_id
SET c.organization_id = s.organization_id
WHERE c.organization_id IS NULL AND c.session_id IS NOT NULL;

UPDATE cart_items ci
JOIN carts c ON c.id = ci.cart_id
SET ci.organization_id = c.organization_id
WHERE ci.organization_id IS NULL;

UPDATE payments p
JOIN orders o ON o.id = p.order_id
SET p.organization_id = o.organization_id
WHERE p.organization_id IS NULL;

UPDATE payment_transactions pt
JOIN payments p ON p.id = pt.payment_id
SET pt.organization_id = p.organization_id
WHERE pt.organization_id IS NULL;

UPDATE tickets t
JOIN orders o ON o.id = t.order_id
SET t.organization_id = o.organization_id
WHERE t.organization_id IS NULL;

UPDATE ticket_scans ts
JOIN tickets t ON t.id = ts.ticket_id
SET ts.organization_id = t.organization_id
WHERE ts.organization_id IS NULL;
```

Тот же приём — для `order_items`, `refunds`, `seat_holds`, структуры залов
(`halls → venues`, `sectors → hall_schema_versions → halls`), переводов, контента
события, AB-таблиц, доставок вебхуков и розыгрышей.

**Гейт качества после бэкфилла:**

```sql
SELECT 'tickets' AS table_name, COUNT(*) AS null_org FROM tickets WHERE organization_id IS NULL
UNION ALL SELECT 'payments', COUNT(*) FROM payments WHERE organization_id IS NULL
UNION ALL SELECT 'carts',    COUNT(*) FROM carts    WHERE organization_id IS NULL
-- ... по всем таблицам класса A
;
```

Переход к следующей фазе — только когда по всем таблицам класса A `null_org = 0`.
Для `raffles` без записей допускается ручное заполнение; строки, которые нельзя
отнести к организации, должны быть перечислены в отчёте команды, а не молча
оставлены NULL.

#### Фаза 2. Запись и чтение через контекст

1. Трейт `TenantScoped` (или базовый `TenantModel`) для моделей класса A:
   - глобальный scope `where organization_id = context->id()`;
   - `creating`: если `organization_id` не задан — подставить из контекста; если
     задан и не совпадает — `NotFoundError`/`TenantContextMissingError`;
   - `organization_id` **не** в `$fillable`: значение приходит только из контекста,
     а не из тела запроса.
2. Для системных задач (миграции, установщик, планировщик, кросс-тенантная
   отчётность) — только `OrganizationContext::withoutScope()`. Глобальный scope не
   должен молча превращаться в «без фильтра» при отсутствии контекста: без
   контекста и без явного bypass — исключение.
3. Репозитории класса A переводятся с `whereHas('order', ...)` на прямой
   `where('organization_id', ...)`. JOIN остаётся только там, где нужны данные
   родителя, но не как единственная защита.
4. `assertOwns()` остаётся на границе записи как вторая линия, но перестаёт быть
   единственной.

#### Фаза 3. Ограничения БД

После того как приложение заполняет колонку на всех путях:

```php
$table->unsignedBigInteger('organization_id')->nullable(false)->change();
$table->foreign('organization_id')->references('id')->on('organizations');
```

Для критичных пар (родитель — ребёнок) — составные индексы и, где возможно,
составные внешние ключи, чтобы `payments.organization_id` не мог разойтись с
`orders.organization_id`:

```sql
ALTER TABLE orders ADD UNIQUE KEY uq_orders_org_id (organization_id, id);
ALTER TABLE payments
  ADD CONSTRAINT fk_payments_order_org
  FOREIGN KEY (organization_id, order_id) REFERENCES orders (organization_id, id);
```

Уникальные индексы, которые нужно пересмотреть:

| Индекс | Было | Стало / решение |
|---|---|---|
| `uq_payments_idempotency` | `(provider, idempotency_key)` | `(organization_id, provider, idempotency_key)`; повтор всё равно ищется по `order_id`, чтобы ключ другого заказа не вернул чужой платёж |
| `uq_carts_active` | generated column по токену/сеансу | не меняется: токен корзины глобально случайный; `organization_id` добавляется как обычный индекс |
| `uq_inventory_session_seat` | `(session_id, seat_id)` | не меняется: сеанс уже принадлежит одной организации |
| `uq_promo_codes_org_code`, `uq_events_org_slug` | уже с `organization_id` | не меняются |

#### Фаза 4. Тесты и мониторинг

Обязательная матрица (минимум):

| Сценарий | Ожидание |
|---|---|
| Пользователь org A: `GET /orders/{id}` заказа org B | 404 |
| `GET /payments/{id}` платежа org B | 404 |
| `GET /tickets/{id}` и `/qr` билета org B | 404 |
| `POST /checkin/scan` билета org B | 404/отказ, без записи скана |
| `GET /media/{id}` файла org B | 404 |
| `POST /promo-codes/validate` кода org B | не раскрывает параметры чужого кода |
| `POST /orders` с `organization_id` org B в теле | поле игнорируется/отклоняется, заказ в org A |
| Планировщик без контекста | падает с `TenantContextMissingError`, а не читает всё |

Плюс ежедневная проверка:

```sql
-- Ни одной строки класса A без организации
SELECT ... FROM <table> WHERE organization_id IS NULL;

-- Ни одной строки, где организация ребёнка не совпадает с родителем
SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id
WHERE p.organization_id <> o.organization_id;
```

Алерт при любом ненулевом результате — это не «шум», а признак обхода скоупа.

### 3.4 Порядок и обратимость

1. Фаза 0 — без изменений схемы, можно выпускать отдельно.
2. Фаза 1 — только additive: nullable-колонки и индексы. Откат — drop column/index.
3. Бэкфилл — идемпотентная команда; повторный запуск не меняет уже заполненные
   строки.
4. Фаза 2 — код; при откате приложения колонки остаются, старый код их игнорирует.
5. Фаза 3 — `NOT NULL` и FK только после нулевого отчёта. Откат `NOT NULL` —
   отдельная миграция; FK снимается первым.
6. Фаза 4 — тесты и мониторинг входят в Definition of Done, а не «после релиза».

### 3.5 Оценка и риски

- **Объём:** 40 таблиц класса A, ~11 класса B. Основная работа — не DDL, а
  бэкфилл `carts`/`raffles` (нет однозначного родителя) и перевод репозиториев.
- **Главный риск:** глобальный scope, включённый до бэкфилла, начнёт отдавать пустые
  списки или падать на старых строках. Поэтому фазы 1 и 2 разделены.
- **Второй риск:** системные процессы (планировщик, вебхуки, reconcile) работают без
  HTTP-контекста. Для них нужен явный `withoutScope()`; иначе они начнут падать — и
  это правильное поведение, но его нужно заложить в код до включения scope.
- **Третий риск:** `carts` без `session_id`. Такие корзины нельзя отнести к
  организации автоматически; решение — либо запретить их (корзина всегда привязана
  к сеансу), либо оставить `NULL` и считать глобальными до первого добавления
  товара.

---

## 4. Что делать дальше

1. **Сейчас:** пункты 2–7 считать закрытыми; не возвращать `svg`, не задавать
   `TRUSTED_PROXIES=*`, не убирать `NOT NULL` с `payments.idempotency_key`.
   Межтенантные проверки HTTP-слоя (Orders/Payments/Tickets/Checkin) считать
   обязательными: их нельзя ослаблять «на время», пока схема не денормализована.
2. **Ближайший спринт:** фаза 0 выполнена — необязательный `organizationId`
   убран из `TicketRepository::find()`/`PaymentRepository::find()` (и `OrderRepository`),
   добавлены `findAny()`/`findAnyByPublicId()`, фолбэк `?? 1` в
   `CartCheckoutService` закрыт. Кросс-тенантные feature-тесты запущены на живой
   MySQL — полный набор зелёный (1189 тестов, 4874 assertions); фикстуры
   `RaffleTest`/`ReferralTest`/`PaymentIdempotencyTest` приведены к инварианту
   «организация заказа = организация позиции».
3. **Фаза 1 применена** к dev-БД: `php artisan migrate` (40 колонок на месте),
   `php artisan tenancy:backfill-organization` (остаток NULL = 0). Гейт
   перехода к фазе 3 — по всем таблицам класса A `null_org = 0` (§3.3).
4. **Фаза 2 (продолжение):** трейт `TenantScoped` реализован, write-side включён
   для `Order`/`Payment`/`Ticket`, поведение покрыто `TenantScopedWriteTest` и
   `TenantScopedReadTest`. Проведена широкая миграция системных путей на
   `OrganizationContext::withoutScope()`: фоновые джобы (Accrue/RefundBonus,
   EnterRaffles, RewardReferral, Backfill/NotifyRaffleWinners, SendOrderNotification),
   сервисы (`PaymentService::createPayment/initiatePayment/processWebhook`,
   `RefundService::refund`, `TicketGeneratorService::generateTicketData`,
   `RecommendationService::forUser`, sweepers заказов, `EventOrderRefunder`),
   обсервер `OrderLoyaltyObserver`, а также прямые тестовые чтения
   (`TicketEmailTemplateTest`, `BonusSpendTest`).
   **Фундаментальное открытие:** глобальный скоуп на `Order` семантически
   несовместим с owner/guest-маршрутами (`OrderController::show` и др.), которые
   читают заказ по праву владельца/гостевого токена через route-model-binding, а не
   по организации запроса. Включение глобального скоупа потребует явной обработки
   этих маршрутов в точке авторизации (или `resolveRouteBinding`), поэтому скоуп
   остаётся выключенным, а write-side + системные обёртки — включёнными и
   валидированными (полный набор зелёный, 1198 тестов).
    **ИТОГ:** глобальный читающий скоуп на `Order` **включён** (`tenantScopeEnabled(): true`)
    и весь набор проходит — `OK (1198 tests, 4892 assertions)`, PHPStan `No errors`.

**Бэкапы: шифрование.** Добавлено шифрование бэкапов AES-256-CBC
(`BackupEncryptor`, HMAC-SHA256 для целостности) перед выгрузкой на Яндекс.Диск.
Ключ — `NABILET_BACKUP_ENCRYPTION_KEY`; команда `backup:run` шифрует по умолчанию
(если ключ задан), флаг `--no-encrypt` отключает. Восстановление — `BackupEncryptor::decrypt()`.
Покрыто `tests/Unit/BackupEncryptorTest.php`.

**Бэкапы: ротация.** `BackupRotation` — чистый класс политики: удаляются только
файлы формата `db_<база>_YYYY-MM-DD_HH-MM-SS.sql[.enc]` /
`full_YYYY-MM-DD_HH-MM-SS.zip[.enc]`; полные архивы и дампы БД — независимые
очереди; только что созданная копия защищена явно (даже при `--keep=0`), а
несуществующая дата или путь с навигацией не считаются бэкапом. `backup:run`
чистит старое ПОСЛЕ успешной выгрузки (`--keep=N`, `--no-rotate`); ручная
команда `backup:rotate` поддерживает `--dry-run`, `--local-only`,
`--remote-only`. Хранение по умолчанию — `NABILET_BACKUP_KEEP=7`. Сбой ротации
не отменяет успешный бэкап: пишется предупреждение. Покрыто
`tests/Unit/BackupRotationTest.php` и
`tests/Feature/Backups/BackupRotationCommandTest.php`. Итог: unit 1037 методов,
feature `OK (1200 tests, 4903 assertions)`, PHPStan `No errors`.
5. **Фаза 3 (подготовлена, не включена):** миграция `NOT NULL` + FK на
   `organizations` + составные UNIQUE `(organization_id, id)` для родительских
   таблиц была написана и применена к dev-БД, но **откачена** после проверки:
   тесты дали 658 ошибок. Причина — `TenantScoped` включён только на
   `Order`/`Payment`/`Ticket`, а остальные ~36 таблиц класса A (carts, sessions,
   halls, sectors и т.д.) пишутся напрямую (`DB::table`) без организации. Чтобы
   включить фазу 3, нужно сперва либо расширить `TenantScoped`/писателей на все
   таблицы класса A, либо перевести прямые вставки на контекст. Подготовленная
   миграция сохранена в `docs/` как образец; код-гейт «null_org = 0» уже пройден.
6. **Постоянно:** `php artisan config:validate` в деплое, `php tests/run.php` и
   `php vendor/bin/phpunit` в CI.
6. **Постоянно:** `php artisan config:validate` в деплое, `php tests/run.php` и
   `php vendor/bin/phpunit` в CI.
