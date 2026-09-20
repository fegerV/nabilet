# NABILET Core

Универсальный ticketing engine: CMS + e-commerce + CRM для организатора + API-платформа.
Монолитная модульная система для полного цикла продажи и контроля билетов.

```
мероприятие → площадка → зал → схема → сеанс → места → цена
   → заказ → оплата → билет → QR → проверка входа → аналитика
```

**Стек:** Laravel 13 (Modular Monolith) · PHP 8.3+ · Vue 3 / TypeScript · MySQL 8.4 ·
Redis (опционально) · REST API · PWA · Android Checker

---

## Источник истины

Контракты проекта — DDL, API, машины состояний — задаются **пакетом `nabilet_core_spec/`**:

```
nabilet_core_spec/
├── NABILET_Core_Technical_Spec_v1.md   обзорная спецификация
├── migrations.sql                      консолидированный DDL (64 таблицы)
├── migrations/001..010                 сплит-миграции в порядке исполнения
├── openapi.yaml                        OpenAPI 3.1 (98 операций)
└── state-diagrams.md                   машины состояний
```

`docs/openapi.yaml` — рабочая копия пакетного контракта.
Исходное ТЗ — `Мысли.md` и `Техническая спецификация разработки.md`.

Расхождения между пакетом и исходным ТЗ, найденные проверкой, и их последствия:
**[`docs/REVIEW-spec-bundle.md`](docs/REVIEW-spec-bundle.md)**. Читать до начала реализации:
там зафиксированы пять потерянных требований и три пробела изоляции арендаторов.

---

## Документация

| Документ | Содержание |
|---|---|
| [`docs/PLAN.md`](docs/PLAN.md) | План реализации: 9 этапов, критический путь, риски, DoD |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Архитектура: модули, хуки, слои, мультитенантность, безопасность |
| [`docs/DATABASE.md`](docs/DATABASE.md) | Модель данных пакета: 56 таблиц, инварианты, политика ON DELETE |
| [`docs/STATE-MACHINES.md`](docs/STATE-MACHINES.md) | Машины состояний Order / Payment / Ticket / Hold / Event / Session |
| [`docs/openapi.yaml`](docs/openapi.yaml) | OpenAPI 3.1: 68 путей, 77 операций, 71 схема |
| [`docs/REVIEW-spec-bundle.md`](docs/REVIEW-spec-bundle.md) | Проверка пакета на реальном MySQL: пробелы, расхождения, пробелы ТЗ |

---

## Структура

```
nabilet/
├── app/Core/            ядро: модули, хуки, ошибки, state machines, tenancy, Money, QrSigner
├── app/Modules/         30 модулей (27 включены), каждый с module.json
├── bootstrap/           сборка приложения, порядок middleware
├── config/              конфигурация ядра
├── database/migrations/ миграции сессии 1 — УСТАРЕЛИ, см. README внутри
├── nabilet_core_spec/   пакет: источник истины по DDL и контрактам
├── docs/                проектная документация
├── plugins/             сторонние расширения
├── tests/               тесты ядра (без зависимостей)
├── tools/               проверки: lint, модули, схема, OpenAPI
└── composer.json
```

> ⚠ `database/migrations/` описывает **другую** схему (66 таблиц, другие имена) и
> сохранён только потому, что покрывает возможности, потерянные пакетом.
> **Не запускайте `php artisan migrate`** на этих файлах. Подробности —
> [`database/migrations/README.md`](database/migrations/README.md).

---

## Быстрый старт

```bash
composer install
cp .env.example .env
php artisan key:generate

# Установка через веб-интерфейс (ТЗ §7)
php artisan serve
# → http://localhost:8000/install
```

Инсталлятор проверяет требования, спрашивает доступ к БД, создаёт администратора и
записывает `storage/install.lock`. Повторный запуск блокируется.

### Проверки без установки

Работают на чистом PHP — без `vendor/`:

```bash
php tools/lint.php                  # синтаксис всех PHP-файлов
php tests/run.php                   # тесты ядра (526 методов, 1089 утверждений)
php tools/verify-purity.php         # домен не зависит от фреймворка (86 файлов)
php tools/modules.php --validate    # граф зависимостей модулей
php tools/verify-openapi.php        # целостность контракта + инварианты §9/§43
php tools/verify-openapi.php nabilet_core_spec/openapi.yaml   # то же для копии пакета
php tools/verify-contract-schema.php        # контракт ≡ схема: нет поля, которого нет в БД
php tools/verify-contract-schema.php nabilet_core_spec/openapi.yaml
php tools/verify-migrations.php     # миграции ≡ пакет: 64 таблицы, 93 FK, 35 CHECK
php tools/verify-state-machines.php # статусы в коде ≡ CHECK-ограничениям в БД
```

> `verify-migrations.php` исполняет все миграции и сверяет результат с
> `nabilet_core_spec/migrations.sql`: таблицы, колонки, именованные индексы,
> внешние ключи (включая `ON DELETE`), CHECK-ограничения и триггер
> иммутабельности. Расхождение — это падение, а не предупреждение.
>
> `verify-state-machines.php` сверяет статусы из PHP-машин состояний с
> CHECK-ограничениями схемы. Он появился после того, как машины и схема разошлись
> втройне: `canceled` вместо `cancelled`, `finished` вместо `closed`/`completed`,
> `failed` вместо `payment_failed` и выдуманный `refunded` у платежа — всё это
> всплыло бы только в продакшене, на первом же возврате или отмене.
>
> `verify-contract-schema.php` сравнивает поля контракта с колонками схемы.
> Контракт и DDL описывают один мир, но ведутся раздельно; там, где они расходятся,
> API обещает поле, которое база не умеет хранить. Именно так нашёлся `revoked_at`:
> контракт объявляет его и `revoked_reason` у `Ticket`, а в `tickets` нет ни того,
> ни другого — при этом домен уже читает `TicketSnapshot::$revokedAt`. Упало бы на
> первом же отзыве. Известные расхождения перечислены в самом инструменте с
> причиной; новое — падение сборки.
>
> `verify-purity.php` держит домен свободным от фреймворка: ни одного импорта
> `Illuminate\*`, фасада, глобального хелпера, файлового или процессного вызова.
> Половина проверки — статическая (по токенам, комментарии и строки отброшены),
> вторая — динамическая: каждый класс реально загружается без `vendor/`. Именно
> вторая половина доказывает утверждение, а не выводит его: класс с родителем из
> Laravel не резолвится и падает здесь, а не в CI.

### CI

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) — два job, оба без `vendor/`:

| Job | Что делает | Зачем |
| --- | --- | --- |
| `verify` | lint, тесты, `verify-purity`, граф модулей, `verify-migrations`, `verify-state-machines`, `verify-openapi` и `verify-contract-schema` для обеих копий, `composer validate` | ловит расхождения кода, контракта и схемы |
| `schema` | накатывает `migrations.sql` и сплит-сет в два разных database на MySQL 8.4, сверяет счетчики (64 таблицы / 678 колонок / 1 триггер), диффит `information_schema.columns`, проверяет триггер иммутабельности в обе стороны | доказывает, что DDL реально исполняется |

Смысл разделения: верификаторы — это статическое сравнение, они ничего не говорят о том,
примет ли MySQL схему. А триггер нельзя выразить CHECK (нельзя сослаться на `OLD`), поэтому
он проверяется исполнением: опубликованная версия схемы зала должна rejected при попытке
изменить `schema_json` или `version`, но смена `status` и правка `draft` — должны пройти.

Ожидаемые счетчики в `schema`-job заданы числами. Если схема растёт — меняйте их в том же
коммите: именно так потеря таблицы становится падением CI, а не тихим diff.

### Проверка схемы пакета (нужен Docker)

```bash
docker run -d --name nabilet-mysql --security-opt seccomp=unconfined \
  -e MYSQL_ROOT_PASSWORD=rootpass -e MYSQL_DATABASE=nabilet -p 3307:3306 mysql:8

docker exec nabilet-mysql mysql -uroot -prootpass -e "CREATE DATABASE v CHARACTER SET utf8mb4;"
docker exec -i nabilet-mysql mysql -uroot -prootpass -D v < nabilet_core_spec/migrations.sql
```

`--security-opt seccomp=unconfined` обязателен для Docker 18.09.2 — иначе MySQL падает
с `Can't create thread to handle bootstrap`.

### Запуск стека

```bash
cp .env.example .env && php artisan key:generate   # ключ нужен до старта
docker compose up -d --build
docker compose exec app php artisan migrate --force
# http://localhost:8080
```

Состав: `app` (php-fpm), `nginx`, `mysql:8.4`, `redis:7`. Переменные — в
`docker-compose.yml` со значениями по умолчанию; переопределяйте через `.env`
(`DB_PASSWORD`, `DB_ROOT_PASSWORD`, `HTTP_PORT`, `DB_EXTERNAL_PORT`).

Два места, где легко ошибиться, поэтому они зафиксированы и в файлах:

- **Исходники не смонтированы с хоста.** Обычный для Laravel паттерн
  `.:/var/www/html` здесь ловушка: в репозитории нет `vendor/` (Composer не запускался),
  и монтирование подменило бы `vendor` из образа пустым каталогом — каждый запрос
  падал бы с «class not found», что выглядит как баг фреймворка. Код живёт в образе
  и отдаётся nginx через именованный volume.
- **После `docker compose build` нужен `docker compose down -v`.** Именованный volume
  заполняется из образа только при первом создании, иначе изменения не появятся.

**Статус: образ ни разу не собирался.** Pull образов с машины разработки не работает
(прокси подменяет TLS: `x509: certificate has expired or is not yet valid`), Docker там
18.09.2 без Compose. CI проверяет `docker compose config` и линтит Dockerfile
(hadolint); полная сборка добавлена в CI, когда появится `composer.lock` — без него
`composer install` не пройдёт, а сборка должна падать на дефекте файлов, а не на
нерешённом вопросе.

---

## Ключевые архитектурные решения

**Продаваемая сущность — `InventoryItem`, а не место в схеме зала.** Это даёт разные
цены для сеансов, VIP, динамическое ценообразование и возврат одного места без
специальных случаев.

**Остаток — количество, а не состояние.** `inventory_items` хранит `capacity` +
`available_quantity`. Одна строка inventory для standing-зоны продаётся N раз; enum
состояния этого не выражает.

**Версия схемы зала неизменяема.** Сеанс ссылается на `schema_version_id`.
Редактирование зала создаёт новую версию — исторические заказы защищены.
Соблюдение обеспечивает триггер `trg_schema_version_immutable`, а не только код.

**Защита от двойной продажи — четыре слоя.** Уникальный `(session_id, seat_id)` +
транзакция с `SELECT … FOR UPDATE` + идемпотентность платежей через уникальные индексы +
CHECK-ограничения (`available_quantity BETWEEN 0 AND capacity`). Проверено под
конкуренцией: 40 одновременных покупателей на одно место дают ровно один hold.

**Идемпотентность на уровне БД.** `payment_transactions(payment_id, provider_event_id)`
уникален: три одинаковых вебхука дают один оплаченный заказ.
`tickets(order_item_id, ticket_index)` уникален: повторная выдача не создаёт дубль.

**Деньги — целые minor units.** Никогда float. `Money::allocate()` гарантирует, что
сумма частей равна целому.

**Мультитенантность fail-closed — но схема пакета её ослабляет.** Запрос без контекста
организации бросает исключение. Однако `organization_id` есть лишь у 12 из 56 таблиц,
поэтому скоуп для билетов и платежей требует JOIN и не подставляется автоматически.
Это главный открытый риск — см. [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) §6.

**Ошибки — вложенный конверт ТЗ §66.** `{"error":{"code","message","details"}}`,
а не RFC 7807.

**Ядро не зависит от Laravel.** Хуки, модули, state machines, Money и подпись QR
тестируются без фреймворка и переиспользуются сервисом Checker.

---

## Расширение

```php
// Событие
add_action('order.paid', function (Order $order): void {
    // n8n, CRM, Telegram, Email — без правки ядра
}, priority: 20);

// Фильтр
add_filter('ticket.price', fn (int $price) => $price + 300, priority: 20);
```

Новый модуль: `app/Modules/MyModule/module.json` + `MyModuleServiceProvider.php`.
Порядок загрузки выводится из `requires` — конфигурацию ядра править не нужно.

---

## Статус

| Область | Состояние |
|---|---|
| Ядро (хуки, модули, ошибки, Money, QR, state machines, tenancy) + домены P2–P7 | ✅ 526 тестов, 1089 утверждений |
| Схема пакета (64 таблицы, 35 CHECK, 1 триггер) | ✅ проверена на MySQL 8.4.11 |
| Матрица конкурентности ТЗ §26 | ✅ 6 из 6 сценариев |
| API-контракт пакета (81 путь, 98 операций) | ✅ описан и проверен |
| CI | ✅ `verify` (без `vendor/`) + `schema` (MySQL 8.4) |
| Граф модулей (30 модулей, 27 включены) | ✅ валиден |
| Laravel-миграции по схеме пакета | ⚪ не написаны |
| Решение по промокодам, переводам, медиатеке | ⚪ требуется — см. REVIEW §3.10 |
| Решение по изоляции арендаторов | ⚪ требуется — см. ARCHITECTURE §6 |
| Инсталлятор, сервисы, Eloquent-модели | ⚪ следующий шаг |
| Редактор схем залов (Konva) | ⚪ следующий шаг |
| Публичная часть, админка | ⚪ следующий шаг |

Текущие приоритеты — в [`docs/PLAN.md`](docs/PLAN.md), раздел «Критический путь».
