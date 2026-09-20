# NABILET Core — Модель данных

> **Источник истины — пакет `nabilet_core_spec/`.**
> DDL: `migrations.sql` (консолидированный) и `nabilet_core_spec/migrations/001..010` (сплит).
> Оба представления проверены на идентичность: **678 колонок, 273 ограничения — совпадают побайтово.**
>
> **Проверено исполнением на MySQL 8.4.11**, а не чтением. Факты в этом документе —
> результат запросов к `information_schema` работающей базы. Разбор пробелов и их причин:
> `docs/REVIEW-spec-bundle.md`.

**Схема:** 64 таблицы · 678 колонок · 93 внешних ключа · 81 уникальный ключ ·
244 индекса · 35 CHECK-ограничений · 1 триггер.

---

## 1. Главная идея: четыре уровня вместо одного

ТЗ §90 формулирует центральное решение. Продаваемая сущность — не место в схеме зала:

```
halls  ──→  hall_schema_versions  ──→  sessions  ──→  inventory_items
(зал)       (неизменяемая версия       (показ)        (продаваемый
            геометрии)                                экземпляр)
```

```
Seat A-12 в hall_schema_versions#7   ← физическая реальность, общая для многих сеансов
InventoryItem #99120                 ← то, что реально продаётся, ценится, бронируется
```

Обратите внимание: в пакете **нет отдельной таблицы `hall_schemas`**. Логическая схема зала
выражена через `halls` + `hall_schema_versions.version`. Это проще, но означает, что
«логической, мутабельной» сущности нет — любое изменение геометрии порождает новую версию.

Именно разделение даёт без специальных случаев: разные цены для разных сеансов,
VIP-тарифы, динамическое ценообразование, блокировку места только на один показ.

### Модель количества, а не состояния

`inventory_items` хранит `capacity` + `available_quantity`, а **не** enum состояния:

```
место:          capacity = 1,     available_quantity ∈ [0, 1]
standing-зона:  capacity = 1000,  available_quantity ∈ [0, 1000]
```

Это строго выразительнее enum: одна строка inventory для standing-зоны продаётся N раз,
и количество — единственный источник истины об остатке.

### Один order_item — много билетов

`tickets.ticket_index` вместе с `uq_tickets_order_item_index(order_item_id, ticket_index)`
позволяет одной позиции заказа породить несколько билетов. Без этого standing-зона
не продаётся количеством. В спеке §39 поле не описано — **пакет здесь впереди спеки**.

---

## 2. Группы таблиц (64)

### 2.1 Идентичность и аренда — 10
`organizations` · `roles` · `permissions` · `role_permissions` · `users` ·
`user_organization` · `user_roles` · `user_sessions` · `login_logs` · `api_keys`

Членство в организации — через `user_organization(user_id, organization_id, role_id)`
с составным первичным ключом: это **основная** роль пользователя в организации.
Поверх неё `user_roles(user_id, organization_id, role_id)` даёт одному пользователю
несколько ролей — например, менеджер мероприятий и кассир одновременно. Уникальность
`(user_id, organization_id, role_id)` не даёт выдать одну роль дважды.

### 2.2 Контент и SEO — 10
`event_categories` · `events` · `event_translations` · `pages` · `seo_meta` · `redirects` ·
`venue_translations` · `page_translations` · `media_assets` · `media_links`

Переводы вынесены в отдельные таблицы с уникальностью по `(entity_id, locale)`:
`event_translations`, `venue_translations`, `page_translations`. Локальный `slug` в
`page_translations` — то, что делает URLs вида `/{locale}/...` разрешимыми (§73).
`seo_meta` — полиморфная по `(entity_type, entity_id, locale)`.

Медиатека: `media_assets` хранит файл, его производные (`variants_json`) и alt-текст;
`media_links` — полиморфная привязка `(entity_type, entity_id)` с `role` и `position`,
то есть назначение и порядок в галерее.

### 2.3 Площадки и схемы залов — 8
`venues` · `halls` · `hall_schema_versions` · `sectors` · `hall_rows` · `seats` ·
`hall_tables` · `standing_zones`

Иерархия: `venues → halls → hall_schema_versions → sectors → {hall_rows → seats | hall_tables | standing_zones}`.

> **Спека §22 называет таблицу `rows`; миграция создаёт `hall_rows`.**
> Выбор миграции правильнее: `ROWS` — зарезервированное слово в MySQL 8.0+.
> Расхождение зафиксировано в `REVIEW-spec-bundle.md` §3.7; спеку нужно поправить.

`hall_tables` не имеет собственного раздела в спеке (упоминается только как `tables`
внутри JSON редактора §46).

### 2.4 Сеансы и inventory — 2
`sessions` · `inventory_items`

`sessions` ссылается на **версию** схемы (`schema_version_id`), не на зал. Это и есть
механизм неизменяемости: сеанс навсегда привязан к той геометрии, что была при публикации.

### 2.5 Продажи — 7
`carts` · `cart_items` · `seat_holds` · `orders` · `order_items` ·
`promo_codes` · `promo_code_redemptions`

Одна корзина = один сеанс. `cart_items` уникален по `(cart_id, inventory_item_id)`.
`order_items` хранит снапшоты (`event_title_snapshot`, `venue_title_snapshot`,
`seat_snapshot_json`) — заказ должен отображаться исторически корректно даже после
переименования мероприятия.

Промокоды (§86): `promo_codes` — справочник кодов с условиями `fixed / percent`,
`scope` (`all / event / category / first_purchase`), окном действия и лимитами;
`promo_code_redemptions` — счётчик использований и аудит-след. `orders.promo_code_id`
связывает заказ с кодом, и `orders.discount_amount` перестаёт быть «скидкой из ниоткуда».

### 2.6 Платежи — 3
`payments` · `payment_transactions` · `refunds`

### 2.7 Билеты и check-in — 5
`ticket_templates` · `tickets` · `checkin_devices` · `ticket_scans` · `offline_bundles`

`offline_bundles` учитывает, что именно и когда получило устройство Checker перед
сеансом: мероприятие, сеанс, списки действительных и отозванных билетов и публичный
ключ (§43). Персональных данных в бандле нет — только публичные идентификаторы и
статусы билетов, так же как в QR-полезной нагрузке.

### 2.8 Уведомления и приватность — 4
`notification_templates` · `notifications` · `consents` · `privacy_requests`

### 2.9 Аналитика — 7
`analytics_events` · `ab_experiments` · `ab_variants` · `ab_assignments` · `ab_metrics` ·
`heatmap_events` · `embed_domains`

### 2.10 Интеграции и система — 8
`webhooks` · `webhook_deliveries` · `webhook_events` · `idempotency_keys` · `ip_rules` ·
`modules` · `settings` · `audit_logs`

---

## 3. Защита от двойной продажи — четыре слоя

### Слой 1 — структурный (UNIQUE)

```sql
uq_inventory_session_seat     (session_id, seat_id)
uq_inventory_session_standing (session_id, standing_zone_id)
```

Одно место и одна зона — не более одной строки inventory на сеанс.

> **Ловушка, которую нужно знать.** Эти два ключа работают **только потому**, что ровно
> одно из полей `seat_id` / `standing_zone_id` заполнено. MySQL считает NULL-значения в
> UNIQUE-ключе различными, поэтому строка с обоими NULL не ограничена ничем и дублируется
> без предела. Именно поэтому добавлено `ck_inventory_target` (см. слой 4).

### Слой 2 — транзакционный (ТЗ §29)

```
BEGIN
  SELECT available_quantity FROM inventory_items WHERE id = ? FOR UPDATE
  DELETE FROM seat_holds WHERE expires_at < NOW(6)
  проверка: requested_quantity <= available_quantity
  UPDATE available_quantity = available_quantity - requested_quantity
  INSERT INTO seat_holds ...
COMMIT
```

`FOR UPDATE` сериализует конкурентов, поэтому проверка и списание атомарны.
**Проверено на реальной конкуренции:** 40 одновременных покупателей на одно место дают
ровно один hold; 60 покупателей на зону ёмкостью 200 — ровно 60 hold и остаток 140.

### Слой 3 — идемпотентность платежей

```sql
uq_payment_transactions_event (payment_id, provider_event_id)
uq_webhook_events_provider_id (provider, provider_event_id)
uq_payments_idempotency       (provider, idempotency_key)
uq_payments_provider_id       (provider, provider_payment_id)
uq_tickets_order_item_index   (order_item_id, ticket_index)
```

Дедупликация — вставкой, а не «проверить-и-записать»: гонка двух одинаковых вебхуков
разрешается уникальным индексом. **Проверено:** 5 одновременных дублей вебхука дают
ровно одну обработанную доставку.

> `provider_event_id` допускает NULL, и NULL-строки не конфликтуют — это осознанно:
> транзакции, созданные приложением (а не провайдером), не имеют внешнего идентификатора.

### Слой 4 — на уровне БД, переживающий рефакторинг (добавлено)

`nabilet_core_spec/migrations/009_integrity_hardening.sql` — 24 CHECK-ограничения и триггер.
До него инварианты держались **только** в коде приложения, и база молча принимала
`available_quantity = -1`.

`010_tz_gaps.sql` добавляет ещё 11 ограничений на новые таблицы и расширяет
`ck_tickets_status` — итого **35 CHECK-ограничений на 18 таблицах**.

```sql
ck_inventory_available_qty  CHECK (available_quantity BETWEEN 0 AND capacity)
ck_inventory_target         CHECK (type='seat' AND seat_id IS NOT NULL AND standing_zone_id IS NULL
                                OR type='standing' AND standing_zone_id IS NOT NULL AND seat_id IS NULL)
ck_inventory_seat_capacity  CHECK (type <> 'seat' OR capacity = 1)
ck_tickets_terminal_exclusive CHECK (NOT (used_at IS NOT NULL
                                      AND (cancelled_at IS NOT NULL OR refunded_at IS NOT NULL)))
```

Полный список — 35 ограничений на 18 таблицах: `inventory_items` (5), `tickets` (3),
`promo_codes` (6), `orders` (2), `order_items` (2), `payments` (2), `refunds` (2),
`seats` (2), `offline_bundles` (2), а также по одному — `cart_items`,
`hall_schema_versions`, `seat_holds`, `sectors`, `sessions`, `standing_zones`,
`promo_code_redemptions`, `media_assets`, `media_links`.

> Клаузул 21, а не 24, потому что три ограничения используют один и тот же текст
> `(quantity > 0)`, и два — `(amount >= 0)`. Уникальны именно ограничения (по имени
> на таблицу), а не их формулировки.

**Принцип отбора:** ограничиваются только те значения, которые перечисляет спека (или
комментарий в самом DDL). Там, где спека молчит — в частности `inventory_items.status`
и `orders.payment_status` — ограничений **нет**, потому что выдумывать перечисление
означало бы угадывать дизайн.

---

## 4. Неизменяемость опубликованной схемы зала (ТЗ §20, §98)

Спека дважды объявляет опубликованную версию неизменяемой, и DDL это повторял — но **ничто
не мешало её изменить**. Проверка на реальной базе показала: `UPDATE hall_schema_versions
SET schema_json = …` проходил, а `DELETE` блокировался (FK `sessions` RESTRICT).

Это самый опасный из пробелов: один случайный `save()` переписывает геометрию зала,
на которую ссылаются уже проданные сеансы, и ничто об этом не сообщает.

CHECK-ограничение не может ссылаться на `OLD`, поэтому нужен триггер:

```sql
CREATE TRIGGER trg_schema_version_immutable BEFORE UPDATE ON hall_schema_versions …
  IF OLD.status IN ('published','archived') THEN
    -- изменение schema_json / version / hall_id / width / height / background_url
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '…';
```

Покрыт и `archived`: архивация происходит **после** мероприятия, и без этого геометрию
уже проведённого события всё ещё можно было бы переписать.

**Разрешено намеренно:** смена `status` (публикация, архивация — нормальный жизненный
цикл), `updated_at`, и любые изменения `draft`-версий.

---

## 5. Политика ON DELETE

73 внешних ключа: **35 CASCADE · 16 SET NULL · 22 RESTRICT**.

### RESTRICT — 22 (продажи, деньги, билеты)

Удаление запрещено для всего, что участвует в денежном или пропускном контуре:

```
organizations → events, venues, orders            (у арендатора есть данные)
orders        → tickets                            (заказ с билетами не исчезает)
order_items   → tickets
payments      → refunds                            (платёж с возвратом не исчезает)
inventory_items → tickets, cart_items, order_items, seat_holds
sessions      → tickets, ticket_scans, hall_schema_versions, venues, halls
events        → tickets
seats         → tickets, inventory_items
```

Это защита от каскадного удаления проданных билетов — самый дорогой вид потери данных.

### CASCADE — 35 (геометрия и служебное)

Удаление зала уносит версии схемы, секторы, ряды, места. Удаление события — сеансы и
inventory. Удаление корзины — позиции и holds. Это структурные вложения.

### SET NULL — 16 (обезличивание)

`orders.user_id`, `payments.*`, `consents.user_id`, `audit_logs.user_id`,
`ticket_scans.device_id` и др. Удаление пользователя **не удаляет его заказы** — это
требование бухгалтерской сохранности и GDPR одновременно.

> **Следствие, о котором надо помнить:** `orders.customer_email` объявлен `NOT NULL`,
> поэтому после удаления пользователя его e-mail остаётся в заказе. Для GDPR это
> правильное поведение (retention), но оно должно быть осознанным, а не случайным.
> Проверено: `DELETE FROM users WHERE id = 1` проходит, когда у пользователя есть заказы.

---

## 6. Мультитенантность — сильное ограничение схемы

`organization_id` присутствует только у **16 из 64** таблиц:

```
ab_experiments · api_keys · audit_logs · checkin_devices · embed_domains · events ·
media_assets · offline_bundles · orders · pages · promo_codes · ticket_templates ·
user_organization · user_roles · venues · webhooks
```

Из добавленных в `010_tz_gaps.sql` тенантность получили `promo_codes`, `media_assets`,
`offline_bundles` и `user_roles`. У `media_assets.organization_id` допустим NULL —
актив без организации (глобальная медиатека) не ломает схему.

**Отсутствует** у `tickets`, `payments`, `refunds`, `carts`, `cart_items`, `order_items`,
`inventory_items`, `seat_holds`, `ticket_scans`, `sessions`, `payment_transactions`,
`notifications`, `privacy_requests` и др.

Следствие: тенант-скоуп для билетов, платежей и check-in требует JOIN в 2–3 шага:

```
tickets → orders.organization_id          (2 шага)
tickets → events.organization_id          (2 шага)
payments → orders.organization_id         (2 шага)
```

**Любой запрос, забывший JOIN, — это утечка между арендаторами**, причём именно на самых
чувствительных сущностях. Это единственное место, где пакет объективно слабее подхода
`fail-closed`, и его стоит закрыть либо добавлением `organization_id`, либо обязательным
скоупом в репозиторном слое с тестом на каждый маршрут.

---

## 7. Известные ограничения

### 7.1 Уникальность при NULL

MySQL считает NULL-значения в UNIQUE-ключе различными. Отсюда два следствия:

- `inventory_items` с обоими NULL — **закрыто** ограничением `ck_inventory_target`.
- `ab_assignments`: `uq_ab_assignment_user(experiment_id, user_id)` и
  `uq_ab_assignment_anon(experiment_id, anonymous_id)` не мешают назначить одного
  пользователя дважды — как анонима и как авторизованного. **Остаётся открытым.**
- `payment_transactions.provider_event_id` и `refunds.provider_refund_id` допускают много
  NULL — осознанно.

### 7.2 Hold не защищён на уровне БД

У `seat_holds` нет ни UNIQUE, ни CHECK на количество. Проверено: второй hold на то же
место принимается, `quantity = 999` на место ёмкостью 1 принимается, `expires_at` в
прошлом принимается. Для standing-зон аддитивность по количеству — это by design, но для
мест (`type = 'seat'`) имеет смысл частичный уникальный индекс активного hold.

Корректность держится **только** на слое 2 (§3). Матрица конкурентности §26 это
подтверждает — но и означает, что любая новая кодовая тропа в обход `FOR UPDATE`
ломает защиту молча.

### 7.3 ~32 таблицы без полевого описания в спеке

Пофайлово описаны ~24 таблицы. Остальные (`ab_*`, `analytics_events`, `api_keys`,
`audit_logs`, `checkin_devices`, `consents`, `embed_domains`, `hall_tables`,
`heatmap_events`, `idempotency_keys`, `ip_rules`, `login_logs`, `modules`,
`notification*`, `pages`, `payment_transactions`, `privacy_requests`, `redirects`,
`seo_meta`, `settings`, `ticket_templates`, `user_sessions`, `webhook_*`) упомянуты
только в прозе — колонки пришлось проектировать при написании DDL.

### 7.4 Аддитивные расхождения: миграция впереди спеки

Ни одно поле, объявленное в спеке, не потеряно. Обратное есть — и два случая критичны:

| Таблица | Поля только в миграции | Почему важно |
|---|---|---|
| §28 `seat_holds` | `converted_at` | Без него нельзя отличить конвертированный hold от истёкшего → остаток восстановится дважды |
| §39 `tickets` | `ticket_index`, `refunded_at`, `expired_at` | Без `ticket_index` не работает продажа количеством |
| §24 `standing_zones` | `name` | Нужен для `uq_standing_sector_name` |
| §26 `inventory_items` | `metadata_json` | — |
| §32 `orders` | `updated_at` | — |

**§28 и §39 нужно дополнить**, иначе разработчик, следующий спеке буквально, построит
схему, ломающую модель количества и машину hold.

### 7.5 Идентификаторы

`public_id CHAR(26)` — ULID, есть у большинства сущностей, но **не у всех**:
`consents`, `ip_rules`, `settings`, `audit_logs`, `analytics_events`, `heatmap_events`,
`ab_metrics`, `ab_variants`, `redirects`, `seo_meta`, `idempotency_keys`,
`webhook_events`, `webhook_deliveries` обходятся без него. Для внутренних таблиц это
нормально, но на публичный API такие сущности выставлять неудобно.

---

## 8. Соответствие имён: сессия 1 → пакет

Пакет — источник истины. Если встретите в старых заметках имя слева, читайте справа:

| Сессия 1 | Пакет |
|---|---|
| `event_sessions` | `sessions` |
| `rows` | `hall_rows` |
| `tables` | `hall_tables` |
| `hall_schemas` + `hall_schema_versions` | `hall_schema_versions` (одна таблица) |
| `ab_tests` / `ab_test_variants` | `ab_experiments` / `ab_variants` |
| `privacy_consents` / `data_requests` | `consents` / `privacy_requests` |
| `outbox_messages` | `webhook_events` |
| `inventory_items.status` (enum) | `capacity` + `available_quantity` |
| `price_minor` | `price_amount` |
| `is_immutable` (флаг) | статус `published` + триггер |
| `settings`, `notification_templates`, `pages`, `redirects`, `ip_rules`, `translations` | те же имена |

Формат ошибок: было RFC 7807 `application/problem+json`, стало
`{"error":{"code","message","details"}}` (ТЗ §66). См. `docs/ARCHITECTURE.md` §7.

---

## 9. Соглашения

- **Идентификаторы.** Внутренние ключи — `BIGINT UNSIGNED AUTO_INCREMENT`. Публичные —
  `public_id CHAR(26)` (ULID). В API отдаётся только `public_id`; внутренние id наружу
  не выходят.
- **Деньги.** `BIGINT` в минорных единицах + `currency CHAR(3)`. Никаких `FLOAT`/`DECIMAL`
  для сумм. `Money::allocate()` сохраняет точную сумму при делении.
- **Время.** `DATETIME(6)` в UTC. Часовой пояс сеанса — отдельная колонка `timezone`.
- **Геометрия.** `DECIMAL(12,3)` — доли миллиметра достаточны и не накапливают ошибку
  округления, в отличие от `FLOAT`.
- **Состояния.** `VARCHAR(32)` + CHECK, а не `ENUM`: значение можно добавить миграцией
  данных, не блокируя таблицу на `ALTER`.
- **JSON.** `*_json` — только для действительно полиморфных данных (снапшоты, метаданные,
  payload). Ничего, по чему нужно фильтровать или соединять.
- **Удаление.** `deleted_at` только у `users` и `events`. Остальное либо защищено
  RESTRICT, либо удаляется физически. Мягкое удаление билетов и заказов не применяется.

---

## 10. Как проверить

```bash
# MySQL 8.4 (для Docker 18.09.2 обязателен seccomp=unconfined)
docker run -d --name nabilet-mysql --security-opt seccomp=unconfined \
  -e MYSQL_ROOT_PASSWORD=rootpass -e MYSQL_DATABASE=nabilet -p 3307:3306 mysql:8

# применить схему
docker exec nabilet-mysql mysql -uroot -prootpass -e "CREATE DATABASE v CHARACTER SET utf8mb4;"
docker exec -i nabilet-mysql mysql -uroot -prootpass -D v < migrations.sql

# метрики
docker exec nabilet-mysql mysql -uroot -prootpass -D v -N -B -e "
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='v' AND table_type='BASE TABLE';"
```

PHP из песочницы к MySQL не подключается (sandbox блокирует сокеты), поэтому проверки
выполняются через `docker exec` — для верификации DDL это эквивалентно.

### Laravel-миграции

```bash
php tools/verify-migrations.php     # миграции ≡ migrations.sql: 13 проверок
```

Исполняет `database/migrations/*.php` через стабы Laravel Schema и сверяет результат с
этим файлом: таблицы, колонки, именованные индексы, внешние ключи вместе с `ON DELETE`,
CHECK-ограничения, триггер. Подробности — `database/migrations/README.md`.
