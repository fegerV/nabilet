# Аудит готовности к шаред-хостингу (Timeweb)

Дата: 2026-09-23
Основание: архитектурная спецификация заказчика (сборка Vue в статику, install-визард,
ограничения шаред-хостинга, ЮKassa-вебхуки, холды мест, крон, очереди, PDF-билеты с QR,
API для Android).

Проверка выполнена **по коду**, а не по заявлениям: каждый пункт подтверждён файлом/строкой.

---

## 1. Состояние репозитория

| | |
|---|---|
| Было | `main` отставала от `origin/main` на **25 коммитов** (своих коммитов: 0) |
| Стало | `HEAD` = `b3926b5`; отставание **0**, своих коммитов сверх `origin/main`: **4** |
| Коммиты | `14b5150` дизайн-система UI → `7158f34` слияние → `40eebde` исключение `node_modules` → `b3926b5` унификация конфигов |

Конфликты слияния разрешены:
- `package.json` (add/add) — объединены зависимости и скрипты;
- `routes/api.php` — сохранена загрузка `Installer` (приоритет) и `Auth`, убран дубль `Events`;
- `WebhookController.php` (add/add) — взята рабочая реализация с проверкой подписи.

Коммиты **не запушены** — только локально.

---

## 2. Матрица соответствия спецификации

### Сборка фронтенда

| Пункт спецификации | Статус | Evidence |
|---|---|---|
| Vue компилируется в статику, Node.js на сервере не нужен | ✅ | `npm run build` → 67 модулей, 2.6 с |
| Выход в `public_html/build` | ✅ | `vite.config.js:19` `outDir: 'public_html/build'` |
| Точки входа | ✅ | `resources/js/app/hall-editor.js`, `resources/js/app/ticket-builder.js` |
| Manifest для Laravel | ✅ | `public_html/build/.vite/manifest.json` |
| `node_modules` не попадает в архив | ✅ (исправлено) | Был закоммичен 6001 файлов — снят с отслеживания (`40eebde`) |

### Визард установки

| Пункт | Статус | Evidence |
|---|---|---|
| Модуль установщика | ✅ | `app/Modules/Installer/{Http/Controllers/InstallerController.php, resources/views/install.blade.php, routes/web.php}` |
| Форма БД (хост, имя, логин, пароль, порт) | ✅ | поля `db_host`, `db_port`, `db_database`, `db_username`, `db_password` |
| Запись `.env` из шаблона | ✅ | `InstallerController` — подстановка `{DB_HOST}` и др. |
| `Artisan::call('migrate', ['--force' => true])` | ✅ | строка 133 |
| Создание админа | ✅ | `Artisan::call('db:seed', '--force')` + `database/seeders/NabiletAdminSeeder.php` |
| `Artisan::call('storage:link')` | ✅ | строка 143, в `try/catch` с предупреждением |
| `key:generate` | ✅ | строка 158 |
| Самоблокировка после установки | ✅ | `storage/install.lock` (строки 391, 399) |
| Поля ЮKassa (Shop ID, API key) | ✅ | `yookassa_shop_id`, `yookassa_api_key` → `{YOOKASSA_SHOP_ID}`, `{YOOKASSA_API_KEY}` |
| **Блок SMTP (MAIL_*)** | ❌ | В форме нет `MAIL_HOST/PORT/USERNAME/PASSWORD/FROM_ADDRESS`; переменные есть только в `.env.example` |

### Ограничения шаред-хостинга

| Пункт | Статус | Evidence |
|---|---|---|
| Переименование `public` → `public_html` | ⚠️ частично | Каталог `public_html` создаётся только сборкой; `config/filesystems.php:55` всё ещё `public_path('storage')`, а не `base_path('public_html/storage')` (есть комментарий «адаптирует installer», но адаптации в коде нет) |
| Отказ от symlink при неудаче | ✅ | `storage:link` в `try/catch` |
| Память: отправка схем порциями | ❌ | Единого эндпоинта с порционной отправкой секторов нет |

### Платежи и холды

| Пункт | Статус | Evidence |
|---|---|---|
| Вебхук-роут без CSRF | ✅ | Роуты в `routes/api.php` → группа `api`, CSRF не применяется |
| Обработчик вебхука ЮKassa | ✅ | `POST /webhooks/payment/{provider}`, проверка подписи, `requiresSignature()` для `yookassa/stripe/kaspi` |
| SDK ЮKassa в `vendor` | ❌ | `yoomoney/yookassa-sdk-php` отсутствует в `composer.json` и `composer.lock`; интеграционного кода нет — только поля в установщике |
| Холд места при выборе | ✅ | `app/Modules/Inventory/Domain/{SeatHold,HoldWindow}.php`, TTL через `NABILET_HOLD_TTL` (по умолчанию 600 с) + grace-окно |
| Снятие просроченных холдов | ❌ **БЛОКЕР** | `routes/console.php:22` вызывает `Schedule::command('seats:clear-expired')`, но **команды не существует**: `app/Console/Commands/` пуст, `$signature` нигде не объявлен |

### Крон и очереди

| Пункт | Статус | Evidence |
|---|---|---|
| Расписание каждую минуту | ⚠️ | Зарегистрировано, но ссылается на несуществующую команду → `php artisan schedule:run` упадёт целиком |
| `queue:work --stop-when-empty` по крону | ❌ | В `routes/console.php` нет |
| `QUEUE_CONNECTION=database` | ❌ | `.env.example:37` — `QUEUE_CONNECTION=redis` (на шаред-хостинге Redis недоступен) |
| Таблица очередей (`jobs`) | ❌ | Миграций `jobs`/`queue` в `database/migrations` нет |

### Билеты: PDF и QR

| Пункт | Статус | Evidence |
|---|---|---|
| Генерация PDF-билета | ⚠️ код есть, пакета нет | `app/Modules/Tickets/Services/TicketGeneratorService.php:34` — `\PDF::loadView(...)`; `barryvdh/laravel-dompdf` **не установлен** → runtime-ошибка |
| Генерация QR | ⚠️ код есть, пакета нет | `TicketGeneratorService.php:55` — `QrCode::format('png')`; `simplesoftwareio/laravel-qrcode` **не установлен** |
| Отправка письмом с вложением | ⚠️ | `app/Modules/Notifications/Mail/TicketPurchasedMail.php` — `Mailable`, вложение PDF; без пакетов не работает |

### API для Android

| Пункт | Статус | Evidence |
|---|---|---|
| Документация API для Kotlin-разработчика | ✅ | `docs/openapi.yaml` (102 КБ), дубль в `nabilet_core_spec/openapi.yaml` |
| Sanctum (Bearer-токены) | ❌ | `laravel/sanctum` отсутствует в `composer.json`/`composer.lock` |
| Версионирование API | ✅ | `bootstrap/app.php:36` — `apiPrefix: 'api/v1'`; модули монтируются без повтора сегмента версии |
| Защита от двойного прохода (`lockForUpdate`) | ❌ | `lockForUpdate` используется в `CartService` и `HoldSweeper`, но **не** в check-in → возможна гонка при одновременном сканировании |
| Логика check-in | ✅ | `app/Modules/Tickets/Domain/CheckinEvaluator.php` — онлайн/офлайн-сценарии, повторный скан |
| Android-приложение | ❌ | `android/` содержит только `README.md` и `CryptoUtils.kt` |
| CI сборки APK | ❌ | `.github/workflows/` — только `ci.yml` (PHP), `android.yml` нет |

---

## 3. Критические блокеры (в порядке важности)

1. **`seats:clear-expired` не существует.**
   Планировщик ссылается на отсутствующую команду → на Timeweb `schedule:run` упадёт,
   просроченные холды **никогда не освобождаются** → места блокируются навсегда.
   Сервис `app/Modules/Inventory/Services/HoldSweeper.php` уже реализован
   (с `lockForUpdate` и аудит-логом) — нужна только artisan-обёртка.

2. **Отсутствуют PHP-пакеты**: `yoomoney/yookassa-sdk-php`, `barryvdh/laravel-dompdf`,
   `simplesoftwareio/laravel-qrcode`, `laravel/sanctum`.
   Код уже вызывает `\PDF::loadView()` и `QrCode::format()` → падение в рантайме.

3. **Очереди неработоспособны на шаред-хостинге**: `QUEUE_CONNECTION=redis`, нет таблицы
   `jobs`, нет `queue:work --stop-when-empty` в расписании.

4. **Нет защиты от гонки при check-in** — два контролёра могут провести один билет дважды.

5. **`public_html` не полноценная замена `public`**: `config/filesystems.php` указывает
   на `public_path('storage')`; перенос каталога не выполнен.

## 4. Что уже исправлено в этой сессии

- Репозиторий синхронизирован с `origin/main` (25 коммитов, 3 конфликта разрешены).
- `node_modules` (6001 файлов) снят с отслеживания — в ZIP не попадёт.
- Удалён `tailwind.config.js`-заглушка: Tailwind резолвит `.js` раньше `.ts`, из-за чего
  игнорировались дизайн-токены и падала сборка. Единый конфиг — `tailwind.config.ts`.
- `.gitignore`: убран мусорный маркер ```` ``` ````, добавлены `public_html/build/`,
  `public_html/storage/`.
- Восстановлен повреждённый `caniuse-lite` (сборка PostCSS падала).
- Проверены обе сборки: `npm run build` → `public_html/build`; `npm run build:preview` →
  `dist-preview/ui-preview.html` (500 КБ).

## 5. Исправлено при прогоне сервера и тестов

Прогон `artisan` и тестов вскрыл, что влитый код **не запускался вообще**:

1. **`seats:clear-expired` не существовала** (блокер №1) — добавлена команда
   `app/Modules/Inventory/Console/ClearExpiredHoldsCommand.php` поверх готового
   `HoldSweeper` и зарегистрирована в `bootstrap/app.php`
   (провайдеры модулей не поднимаются, см. `bootstrap/providers.php`).
   Проверено: `artisan schedule:list` → `* * * * * php artisan seats:clear-expired`;
   запуск на реальной БД возвращает статистику.
2. **Фатальные ошибки Filament роняли весь artisan** (значит, и `schedule:run`):
   - `AnalyticsResource\Pages\Dashboard`, `BackupResource\Pages\ManageBackups`,
     `SystemResource\Pages\SystemStatus` объявляли нестатический `$view`
     (`BasePage::$view` — статический) → `Cannot redeclare static ... as non static`;
   - те же три страницы наследовались от `Filament\Pages\Page` вместо
     `Filament\Resources\Pages\Page` → `Method ...::route does not exist`;
   - `getPages()` ссылались на несуществующие классы `Pages\Reports` и
     `Pages\SystemLogs` (нет ни классов, ни шаблонов) → ссылки убраны.
3. **Порядок миграций**: `2024_01_15_000001_create_event_content_tables.php`
   создавал `event_speakers` с FK на `events` раньше, чем `events` появлялась
   (миграция `2026_09_20_000200_002_content.php`). Перенесена в конец очереди —
   `2026_09_22_001500_create_event_content_tables.php`.
4. **`OrderService::paginate()` не существовал**, а `OrderController` вызывал
   `create()`/`cancel()`, которых в сервисе нет (`createOrder`/`cancelOrder`).
   Выборка реализована **fail-closed**: без `organization_id` возвращается пустая
   страница, а не заказы всех арендаторов (у модели `Order` нет глобального
   tenant-скоупа).
5. **Устаревший тест**: `EventApiTest` обращался к `/api/ping`, тогда как API
   смонтирован на `/api/v1` → исправлено.

Результат: `php artisan test` — **5/5**, `php tests/run.php` — **639 методов,
0 падений, 1324 утверждения**.

## 6. Новые блокеры, найденные при запуске

1. **Модуль Orders написан под архивную схему.** `OrderService::createOrder()`
   пишет `session_id`, `customer_name`, `metadata`, `subtotal`, `tax_amount`,
   а таблица `orders` (миграция `..._004_sales.php`) содержит
   `subtotal_amount`, `fee_amount`, `payment_status`, `order_number`,
   обязательный `customer_email` и **не содержит** `session_id`/`metadata`.
   Путь записи заказов (`POST /api/v1/orders`) при вызове упадёт.
   Это тот самый разрыв «две схемы», о котором предупреждает
   `database/archive-superseded-migrations/README.md`. Требуется переписать
   `OrderService`/`Order`/`OrderRepository` под пакет `nabilet_core_spec/`.
   → **ИСПРАВЛЕНО, см. §8.**
2. **Почти все API-роуты публичны.** `auth:api` стоит только у Payments;
   Orders, Tickets, Inventory, Cart, Sessions — без аутентификации.
   Для тикетницы это означает публичный доступ к заказам и билетам.
3. **`phpunit.xml` не содержит набора `Unit`** — `artisan test` прогоняет только
   `tests/Feature` (5 тестов), а 639 Unit-методов живут в отдельном раннере
   `php tests/run.php` и в CI-гейт не попадают.

## 7. Известное дублирование

Два редактора схем залов:
- `resources/js/components/HallEditor.vue` (+ `resources/js/app/hall-editor.js`) — пришёл с сервера;
- `resources/js/pages/hall-editor/HallEditorPage.vue` — дизайн-система с амфитеатром,
  12 инструментами, автосохранением.

Требуется решение: оставить один и перенести ценные возможности (амфитеатр, автосохранение) в него.

## 8. Модуль Orders приведён к реальной схеме (проверено на живой БД)

Блокер №1 из §6 закрыт. Проверка не по лейблу «готово», а по факту: заказ
создаётся, а результат читается из `orders`/`order_items`/`inventory_items`.

### Что было сломано

| Место | Писало / читало | В схеме есть |
|---|---|---|
| `OrderService::createOrder()` | `session_id`, `customer_name`, `metadata`, `subtotal`, `tax_amount` | `order_number`, `customer_email` (NOT NULL), `subtotal_amount`, `discount_amount`, `fee_amount`, `payment_status` |
| `OrderItem` | `total_price`, `metadata` | `total_amount`, `event_title_snapshot` (NOT NULL) |
| `OrderService::cancelOrder()` | `$this->stateMachine->canTransition($order, 'cancelled')` | `OrderStateMachine::make()->transition()` |
| `OrderService::completeOrder()` | статус `completed` | статуса нет в `ck_orders_status`, есть `paid` |
| `InventoryItem` | `price`, `quantity` | `price_amount`, `capacity` |
| `public_id` (Payment/Order/Ticket/User/Organization) | `Str::uuid()` = 36 символов | `CHAR(26)` под ULID |

Две ошибки в цепочке были **гарантированными фатальными**, а не «редкими»:
`OrderStateMachine` — это фабрика, у неё нет метода `canTransition()`; и
`$payment->order->service()` — метода `service()` у модели `Order` не существует.

### Что сделано

1. **`OrderService` переписан** под схему:
   - `createOrder()` пишет `order_number` (`NB-YYYYMMDD-XXXXXXXX` на ULID-хвосте,
     `uq_orders_number` — UNIQUE), `customer_email`, `subtotal_amount`,
     `discount_amount`, `fee_amount`, `total_amount`, `status = pending`,
     `payment_status = pending`; позиции получают `total_amount` и снимок
     `event_title_snapshot` / `session_title_snapshot` / `venue_title_snapshot`.
   - **Цена берётся из `inventory_items.price_amount`, а не из запроса.**
     Поле `items.*.price` из `StoreOrderRequest` больше не читается: доверие к
     клиентской цене — это покупка места за 50 000 по цене 1. Есть тест.
   - **Блокировка строки при резервировании** (`lockForUpdate`). Без неё две
     одновременные кассы продают последнее место дважды.
   - `cancelOrder()` выполняет переход через `transition()` (нелегальный переход
     даёт `InvalidStateTransitionError` → 409) и возвращает места в продажу
     **только если заказ реально их держал** (`OrderStateMachine::occupyingInventory()`).
   - `completeOrder()` → **`markPaid()`**: статус `paid` + `payment_status = succeeded`
     + `paid_at`. Статуса `completed` в `ck_orders_status` нет.
   - Добавлен `markAwaitingPayment()`. Переход `pending → paid` **запрещён**
     машиной состояний намеренно, поэтому оплата идёт
     `pending → awaiting_payment → paid`; `applyPayment()` проходит эту цепочку
     сам (провайдер может сообщить `succeeded` одним шагом) и идемпотентен —
     повторный вебхук не падает и не дублирует переход.
2. **`InventoryItem` приведён к схеме**: `price` → `price_amount`,
   `quantity` → `capacity`, добавлены `metadata_json` и генерация `public_id`
   (ULID base32, ровно 26 символов).
3. **`StoreOrderRequest` приведён к схеме**: `customer_email` обязателен,
   `customer_phone` добавлен, `promo_code_id` добавлен; `customer_name`,
   `metadata`, `items.*.price` оставлены **nullable и игнорируются** (колонок
   нет — иначе 422 на корректных запросах старых клиентов).
4. **`public_id` — везде ULID, а не UUID** (Payment, Order, Ticket, User,
   Organization). `CHAR(26)` и UUID из 36 символов: в strict mode INSERT падает,
   в нестрогом значение обрезается.
5. **`PaymentService`**: убраны два вызова несуществующего `canTransition()`,
   `$payment->order->service()` заменён на инжектированный `OrderService`.
6. **Убрано 17 неявно nullable параметров** (deprecation PHP 8.4) — тестовый
   вывод больше не засорён предупреждениями.
7. **`tools/verify-models-schema.php`**: из ratchet-списка `INVENTED_FIELDS`
   удалены теперь уже исправленные записи `InventoryItem`, `Order`, `OrderItem`
   (список «может только сокращаться»).

### Проверка (по факту, а не по лейблу)

Новый тест `tests/Feature/Orders/OrderWritePathTest.php` — 14 методов, реальная
БД (`nabilet_testing`, PostgreSQL 5433), `RefreshDatabase`:

- заказ создаётся и читается из `orders`: суммы, `customer_email`, `status`,
  `payment_status`, `currency`, `public_id` длиной 26, `order_number` по маске;
- позиция создаётся с `total_amount` и снимком названия события; у `order_items`
  **нет** `updated_at` (проверяется через `information_schema`);
- места реально списываются и **возвращаются** при отмене;
- цена из запроса игнорируется;
- запрос больше мест, чем есть, отвергается, и транзакция откатывается
  (ни заказа, ни позиций, склад не тронут);
- оплаченный заказ отменить нельзя, и места при этом не возвращаются;
- `pending → paid` напрямую запрещён; `applyPayment` идемпотентен; половина
  суммы заказа не переводит его в `paid`;
- отчёт по выручке считает только `paid`;
- `POST /api/v1/orders` без `customer_email` → 422 в конверте §66
  (`error.code = VALIDATION_ERROR`, поле в `error.details.fields`).

Итог: `php artisan test` — **19/19 (57 утверждений)**,
`php tests/run.php` — **639 методов, 0 падений**,
`tools/verify-purity.php` — **101 файл, 0 нарушений**.

### Осталось в этом классе (не сделано)

1. **Модуль Payments сломан так же, как был сломан Orders.** Пишет несуществующие
   колонки: `payments.organization_id`, `method`, `webhook_url`, `metadata`
   (в схеме `metadata_json`), `succeeded_at` (в схеме `paid_at`),
   `failure_code`/`failure_message`/`failed_at`; `payment_transactions.metadata`
   (в схеме `payload_json`). `POST /api/v1/orders` создать заказ может, но
   провести оплату — нет. Поэтому `markAwaitingPayment()` сейчас **не вызывается**
   из `PaymentService::createPayment()`: метод всё равно упал бы раньше на
   колонках. Требуется отдельная правка модуля Payments.
2. **`HallSchema` указывает на таблицу `hall_schemas`, которой нет** —
   единственная оставшаяся ошибка `tools/verify-models-schema.php`. Реальная
   модель — `HallSchemaVersion` (`hall_schema_versions`) с версионированием,
   а `VenueController::storeSchema/updateSchema/deleteSchema` и
   `TicketGeneratorService::importHallSchema()` пишут в несуществующую таблицу.
   Нужна переработка с логикой инкремента версии, а не механическая замена имён.
3. **`CartService` читает `inventory_items.unit_price`** (колонки нет; есть
   `price_amount`) — итог корзины будет нулевым. `CartItemResource` читает
   `->price`.
