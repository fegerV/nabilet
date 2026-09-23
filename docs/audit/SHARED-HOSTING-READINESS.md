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
