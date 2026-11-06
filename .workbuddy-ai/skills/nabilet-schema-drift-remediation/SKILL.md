---
name: nabilet-schema-drift-remediation
description: Исправлять расхождение «модель ↔ реальная схема» в проекте NABILET (Laravel 13 + Filament, C:\Project\nabilet). TRIGGER при любом «X не работает / падает при записи», при работе с модулями Orders/Payments/Cart/Inventory/Tickets/Venues, при правке Eloquent-моделей, при запуске tools/verify-*.php, а также когда нужно доказать работоспособность write-path тестом на реальной БД, а не по лейблу «готово». Содержит: список верификаторов в tools/, ratchet INVENTED_FIELDS, конверт ошибок §66 и его последствия для тестов, ловушку «провайдеры модулей не поднимаются», запуск Feature-тестов на локальном PostgreSQL 5433.
agent_created: true
---

# NABILET: устранение расхождения моделей и схемы

## Главная причина большинства поломок

В проекте **две схемы**:

1. `nabilet_core_spec/migrations.sql` (+ `nabilet_core_spec/migrations/*.sql`) —
   **источник истины**;
2. `database/migrations/*.php` — сгенерированы из неё (`tools/gen-migrations.py`),
   совпадают по колонкам.

А модели в `app/Modules/*/Models/` и `app/Models/` писались **по описанию
домена, а не по DDL**. Отсюда систематический класс баг: модель называет
колонку, которой нет. Обращение к такой колонке в Eloquent даёт `null`, а не
ошибку, поэтому баг не виден до первого INSERT/чтения цены.

Примеры, уже подтверждённые: `inventory_items.price` (в схеме `price_amount`),
`quantity` (в схеме `capacity`), `orders.session_id`/`metadata`/`subtotal`
(в схеме `subtotal_amount`/`fee_amount`/`payment_status`/`order_number`),
`order_items.total_price` (в схеме `total_amount`), `payments.succeeded_at`
(в схеме `paid_at`).

**Правило: сначала прочитать DDL, потом писать код.** Не доверять ни именам в
существующих моделях, ни docblock'ам.

## Шаг 1 — найти дрейф машинно, не глазами

В репозитории есть готовый набор верификаторов, запускаемых напрямую (vendor не
нужен):

```bash
PHP="C:/Users/Professional/AppData/Local/VertexCMS/tools/php/php.exe"

$PHP tools/verify-models-schema.php      # модели ↔ схема (главный)
$PHP tools/verify-purity.php             # Core/Errors и Modules/*/Domain без фреймворка
$PHP tools/verify-contract-schema.php    # OpenAPI-контракт ↔ схема
$PHP tools/verify-state-machines.php     # статус-колонки без CHECK
$PHP tools/verify-error-envelope.php     # конверт §66
$PHP tools/verify-module-structure.php   # module.json / реестр модулей
$PHP tools/verify-autoload.php           # PSR-4
```

`verify-models-schema.php` сверяет `$fillable` каждой модели с колонками из
`nabilet_core_spec/migrations.sql` и печатает `$table not in the schema` и
поля-фантомы.

### Ratchet `INVENTED_FIELDS`

Поля-фантомы перечислены в `tools/verify-models-schema.php` в константе
`INVENTED_FIELDS` — это **список задач, а не разрешение**. Каждая запись = «модель
неправильная, надо починить».

**Когда починил поле — удали его запись из `INVENTED_FIELDS`** (список «может
только сокращаться»), затем перезапусти верификатор. Так в этой сессии были
удалены записи `InventoryItem`, `Order`, `OrderItem`.

## Шаг 2 — исправить модель под DDL

Механическая замена имён + проверка типов:

- деньги в схеме всегда `*_amount` (`price_amount`, `subtotal_amount`,
  `total_amount`) — НЕ `price`/`subtotal`;
- `inventory_items` — это `capacity` + `available_quantity`, НЕ `quantity`;
- у `order_items` **нет** `updated_at` → в модели `public const UPDATED_AT = null;`
  (иначе Eloquent пишет несуществующую колонку при INSERT);
- `metadata` в `payments` — это `metadata_json`;
- `payment_transactions.payload_json`, НЕ `metadata`.

### `public_id` — ULID, НЕ UUID

Все `public_id` объявлены `CHAR(26)` (ULID в base32). `Str::uuid()->toString()`
даёт 36 символов: в strict mode INSERT падает, в нестрогом значение обрезается
до 26. Правильно:

```php
$model->public_id = (string) \Illuminate\Support\Str::ulid()->toBase32(); // ровно 26
```

Ставить в `boot()`-хук модели:

```php
protected static function boot(): void
{
    parent::boot();
    static::creating(function (self $model): void {
        if (empty($model->public_id)) {
            $model->public_id = (string) Str::ulid()->toBase32();
        }
    });
}
```

### Машины состояний: фабрика, а не машина

`OrderStateMachine` / `PaymentStateMachine` / `RefundStateMachine` — это
**фабрики** со статическим `make()`, возвращающим `Nabilet\Core\StateMachine\StateMachine`.
У самих фабрик методов `canTransition()`/`can()` **нет**. Частая ошибка в сервисах:

```php
// СЛОМАНО: Call to undefined method, и машина вообще не построена
if (!$this->stateMachine->canTransition($order, 'cancelled')) { ... }

// ПРАВИЛЬНО
$machine = OrderStateMachine::make();
$machine->transition($order->status, OrderStateMachine::CANCELLED, ['order_id' => $order->id]);
```

`transition()`/`assert()` сами бросают `InvalidStateTransitionError` (HTTP 409,
код `INVALID_STATE_TRANSITION`) — это лучше, чем `can()` + `RuntimeException`,
потому что ошибку нельзя проигнорировать.

**Статусы брать из CHECK-ограничений**, не из головы:
`ck_orders_status` = `pending, awaiting_payment, payment_failed, paid, cancelled,
expired, partially_refunded, refunded`. Статуса `completed` НЕТ — есть `paid`.
`ck_payments_status` = `pending, waiting_for_capture, succeeded, canceled (одна
`l`!), failed`.

Важно: `pending → paid` в `OrderStateMachine` **запрещён**. Оплата идёт
`pending → awaiting_payment → paid`. Если провайдер сообщает `succeeded` одним
шагом, сервис должен пройти цепочку сам.

## Шаг 3 — доказать работу тестом на реальной БД

Схема в тестах — PostgreSQL, поэтому `RefreshDatabase` даёт настоящую схему со
всеми FK и CHECK. Это позволяет писать E2E-тесты write-path.

```xml
<!-- phpunit.xml -->
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_PORT" value="5433"/>
<env name="DB_DATABASE" value="nabilet_testing"/>
<env name="DB_USERNAME" value="nabilet"/>
```

```bash
$PHP artisan test                       # Feature (в т.ч. E2E)
$PHP tests/run.php                      # Unit: 639 методов, vendor не нужен
```

Проверка «по факту» = создать объект сервисом и **прочитать строки из БД**
через `DB::table(...)`, а не только `assertStatus`. Тест «эндпоинт отвечает
200/401/403» ничего не доказывает: сломанный код его проходит.

Образец: `tests/Feature/Orders/OrderWritePathTest.php`.

### Цепочка FK для фикстур (нужна для заказа)

```
organizations → events → venues → halls → hall_schema_versions → sessions → inventory_items
```

`sessions` требует `event_id`, `venue_id`, `hall_id`, `schema_version_id` — все
NOT NULL. `inventory_items` требует ровно один «якорь» (`ck_inventory_target`):
`seat_id` XOR `standing_zone_id`. Для `capacity > 1` нужен **`standing`**
(у `type='seat'` есть `ck_inventory_seat_capacity`: `capacity = 1`), а стоячая
зона требует `sectors.schema_version_id`.

## Ловушки, которые дорого стоят времени

### Провайдеры модулей НЕ поднимаются

`bootstrap/providers.php` намеренно не регистрирует
`Nabilet\Core\NabiletServiceProvider`, поэтому **ни один** провайдер модуля не
загружается. Регистрировать их целиком нельзя: каждый вызывает
`loadRoutesFrom()`, и роуты задублируются.

Роуты при этом работают: `routes/api.php` вручную подключает
`app/Modules/*/routes/api.php` (каталог **в нижнем регистре**, не `Routes/`).

Новые команды и биндинги регистрировать точечно в `bootstrap/app.php`:

```php
->withCommands([\Nabilet\Modules\Inventory\Console\ClearExpiredHoldsCommand::class])
```

При этом контейнер всё равно автовайрит конкретные классы через рефлексию —
`app(OrderService::class)` работает и без `singleton()`.

### Конверт ошибок §66 ломает обычные ассерты

`ApiExceptionRenderer` переводит `ValidationException` в `ValidationError`, и
ответ приходит **не** в формате Laravel:

```json
{"error":{"code":"VALIDATION_ERROR","message":"…","details":{"fields":{"customer_email":["…"]}}}}
```

Поэтому `assertJsonValidationErrors(['customer_email'])` **не работает** (он
ищет ключ `errors`). Правильно:

```php
$response->assertStatus(422);
$response->assertJsonPath('error.code', 'VALIDATION_ERROR');
$this->assertArrayHasKey('customer_email', $response->json('error.details.fields'));
```

Неожиданные исключения отдаются как `INTERNAL_ERROR` с сохранением статуса —
реальное сообщение уходит в лог, клиент его не видит.

### Пустой `$view`/базовый класс в Filament роняет весь artisan

Нестатический `protected string $view` в странице-ресурсе (базовый
`BasePage::$view` статический) и наследование от `Filament\Pages\Page` вместо
`Filament\Resources\Pages\Page` дают фатальную ошибку **при загрузке artisan**,
то есть ломают и `schedule:run`, и тесты.

### Неявно nullable параметры (PHP 8.4)

`function f(int $x = null)` → deprecation в PHP 8.4 и мусор в выводе тестов.
Правильно `?int $x = null`.

## Порядок действий (кратко)

1. `tools/verify-models-schema.php` — найти дрейф.
2. Прочитать DDL в `nabilet_core_spec/migrations/*.sql` для затронутых таблиц.
3. Исправить модель: имена колонок, `UPDATED_AT = null` при необходимости,
   ULID `public_id`.
4. Исправить сервис/репозиторий: писать только существующие колонки; переходы —
   через `StateMachine::make()->transition()`; цены и количества брать из БД,
   **не из запроса**; резервирование — под `lockForUpdate()`.
5. Удалить исправленные записи из `INVENTED_FIELDS`, перезапустить верификатор.
6. Добавить E2E-тест на реальной БД, читающий строки из БД.
7. `artisan test` + `tests/run.php` + `verify-purity.php` — всё должно быть зелёным.
8. Записать результат в `docs/audit/SHARED-HOSTING-READINESS.md` (раздел по
   сессии) и в `.workbuddy-ai/memory/YYYY-MM-DD.md`.

## Известные незакрытые места (тот же класс)

- **Модуль Payments**: пишет `organization_id`, `method`, `webhook_url`,
  `metadata`, `succeeded_at`, `failure_code`, `failure_message`, `failed_at`
  (в схеме нет); `payment_transactions.metadata` вместо `payload_json`.
  Заказ создать можно, оплатить — нет.
- **`HallSchema`** → таблица `hall_schemas` не существует. Нужен
  `HallSchemaVersion` (`hall_schema_versions`) с инкрементом версии;
  править `VenueController::storeSchema/updateSchema/deleteSchema` и
  `TicketGeneratorService::importHallSchema()`.
- **`CartService`** читает `inventory_items.unit_price` (колонки нет) →
  итог корзины 0. `CartItemResource` читает `->price`.
