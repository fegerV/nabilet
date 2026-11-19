# Информационные киоски продажи билетов

> Модуль `app/Modules/Kiosk`, добавлен в 1.6.0. Документ описывает устройство,
> админский контур, API киоска, QR-поток, эксплуатацию и честные ограничения.

## 1. Зачем киоск — это устройство, а не пользователь

Киоск стоит в фойе и продаёт билеты без кассира. У него нет почты, пароля и
браузерской сессии. Если выдать ему обычную учётку администратора или гостевой
`X-Cart-Token`, получится плохо:

- сессия человека живёт часами и утекает вместе с токеном;
- гостевой токен корзины даёт доступ к чужим заказам этого же браузера;
- отозвать один киоск без выкидывания всего персонала было бы невозможно.

Поэтому киоск — **устройство**:

- префикс `nbks_…` (у чекера `nbck_…`, у пользователя — сессионный токен);
- в базе хранится только `kiosk_token_hash = sha256(token)`, сам токен
  показывается **ровно один раз** при создании или ротации;
- middleware `AuthenticateKiosk` ищет устройство по хешу **вне тенант-скоупа**,
  затем ставит `organization_id` из найденной строки, перекрывая поддомен
  white-label;
- Организация fail-closed: нет устройства — 401, не активно — 401.

Все данные — **общая база** с сайтом. киоск не получает свою копию БД и не
синхронизирует места: он ходит в тот же `/api/v1` и использует те же сервисы
`CartService → CartItemService → CartCheckoutService → PaymentService`, что и
витрина. Барьер от двойной продажи (`lockForUpdate()` + атомарный `decrement`
в `CartItemService`) работает для сайта и киоска одинаково.

## 2. Состав модуля

| Файл | Роль |
|---|---|
| `database/migrations/2026_11_19_000100_create_kiosk_devices.php` | `kiosk_devices` — реестр, токены, адреса, флаги, директивы обновления |
| `database/migrations/2026_11_19_000200_create_kiosk_sales.php` | `kiosk_sales` — продажи с идемпотентностью по `(device, client_sale_id)` |
| `app/Modules/Kiosk/Domain/KioskDevice.php` | статусы `active/disabled/revoked`, `sales_enabled`, `maySell()` |
| `app/Modules/Kiosk/Domain/KioskSale.php` | статусы продажи и machine переходов |
| `app/Modules/Kiosk/Models/KioskDevice.php`, `KioskSale.php` | Eloquent, `TenantScoped` с читающим скоупом |
| `app/Modules/Kiosk/Services/KioskTokenIssuer.php` | выдача, ротация, отзыв, heartbeat |
| `app/Modules/Kiosk/Services/KioskAnalyticsService.php` | аналитика парка и киоска |
| `app/Modules/Kiosk/Http/Middleware/AuthenticateKiosk.php` | аутентификация устройства |
| `app/Modules/Kiosk/Http/Controllers/Admin/KioskDeviceController.php` | админка |
| `app/Modules/Kiosk/Http/Controllers/KioskController.php` | API устройства |
| `app/Modules/Kiosk/Console/SyncKioskSalesCommand.php` | `kiosks:sync-sales` — догоняет статус заказа |
| `resources/js/pages/admin/AdminKiosksPage.vue` | страница «Киоски» в админке |

## 3. Админка: `/admin/kiosks`

### 3.1 Что можно делать

- **Создать киоск**: название, адрес расположения, примечание, платформа,
  версия приложения, флаг продаж. Ответ содержит одноразовый токен.
- **Увидеть ID**: `public_id` (ULID, 26 символов) показан в строке таблицы.
  Числовой `id` — внутренний; API принимает оба, но UI использует `public_id`.
- **Включить/выключить продажи**: кнопка в строке. Это отдельный флаг
  `sales_enabled`: киоск остаётся на связи, получает heartbeat/конфиг/обновление,
  но не может создать продажу (409 `KIOSK_SALES_DISABLED`).
- **Временно отключить устройство**: `status=disabled`. Токен перестаёт работать
  (401 `KIOSK_NOT_ACTIVE`), продажи выключаются принудительно. Можно вернуть
  в `active` — токен тот же (устройство не терялось).
- **Отозвать**: `status=revoked`, необратимо. Старый токен не воскресить:
  PATCH со `status=active` на отозванном киоске получает 422. Восстановление —
  только новый POST (новая строка, новый хеш).
- **Ротировать токен**: старое устройство отзывается, создаётся новое;
  история продаж остаётся привязанной к старому.
- **Удалённо обновить**: выдать директиву (версия, URL архива, SHA-256).
  Сервер **не исполняет код и не хранит архив** — он только публикует директиву;
  киоск забирает её heartbeat'ом и сообщает результат.
- **Аналитика**: карточки (устройства, на связи, продающие, продажи и выручка
  сегодня/за 7 дней), топ киосков, карточка устройства со статистикой и
  последними продажами.

### 3.2 Админские эндпоинты

Все — под `auth:api` + `admin`, префикс `/api/v1`:

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/admin/kiosks?per_page=50` | список + `summary` |
| GET | `/admin/kiosks/analytics` | только аналитика |
| POST | `/admin/kiosks` | создать, ответ `data.device` + `data.token` |
| GET | `/admin/kiosks/{public_id}` | карточка + статистика + продажи |
| PATCH | `/admin/kiosks/{public_id}` | имя/адрес/платформа/версия/`sales_enabled`/`status` |
| POST | `/admin/kiosks/{public_id}/rotate-token` | ротация, новый токен один раз |
| POST | `/admin/kiosks/{public_id}/revoke` | отзыв |
| POST | `/admin/kiosks/{public_id}/update` | директива обновления |
| POST | `/admin/kiosks/{public_id}/update/cancel` | отмена директивы |
| GET | `/admin/kiosks/{public_id}/sales?status=` | журнал продаж |

Чужой киоск — 404 (`assertCanAccess`), а не 403: это не даёт перебором узнать,
что у другой организации есть устройство с таким id.

## 4. API киоска

Все запросы под `Authorization: Bearer nbks_…`. Префикс `/api/v1`.

| Метод | Путь | Тело | Ответ |
|---|---|---|---|
| POST | `/kiosk/heartbeat` | `app_version?`, `config_version?` | `status`, `sales_enabled`, `server_time`, `update` |
| GET | `/kiosk/config` | — | `kiosk_id`, `name`, `sales_enabled`, `currency`, `update` |
| POST | `/kiosk/update-result` | `status: applied\|failed`, `version`, `error?` | итоговая директива |
| POST | `/kiosk/sales` | `client_sale_id` (UUID), `session_id`, `inventory_item_id`, `customer_email?`, `holder_name?` | sale + `payment_url`, QR позже |
| GET | `/kiosk/sales/{client_sale_id}` | — | статус и `qr_payload` (только после выпуска) |

Пример продажи:

```json
POST /api/v1/kiosk/sales
Authorization: Bearer nbks_…
{
  "client_sale_id": "7e57d9d0-0c0a-4b6b-8b9d-0c1f5b0a0a11",
  "session_id": 42,
  "inventory_item_id": 913,
  "customer_email": "buyer@example.test"
}
```

Ответ:

```json
{
  "data": {
    "client_sale_id": "7e57d9d0-…",
    "status": "awaiting_payment",
    "order_public_id": "01J…",
    "amount_minor": 5000000,
    "currency": "RUB",
    "qr_payload": null,
    "payment_url": "…"
  }
}
```

`session_id` принимает и числовой id, и `public_id` (`Session::findOrFailBySessionId`),
но сеанс чужой организации — 404.

### Идемпотентность

`client_sale_id` генерирует **устройство до запроса**. `UNIQUE (kiosk_device_id,
client_sale_id)` означает: повторный POST после обрыва связи возвращает ту же
продажу с тем же заказом (200), а не создаёт второй билет. Ключ платежа
детерминирован: `kiosk:<device_id>:<client_sale_id>` — новый ULID на каждый
ретрай не защитил бы от двойного платежа.

Продажа создаётся в два этапа: сначала под блокировкой строки устройства
резервируется строка `status=pending`, затем (уже без транзакции) создаются
корзина, заказ и платёж, и статус переходит в `awaiting_payment`. Ошибка второго
этапа фиксируется в записи как `failed` — экран видит причину при опросе,
а не «тихий» 500. Повтор с тем же `client_sale_id` после ошибки вернёт ту же
запись: чтобы попробовать снова, устройство должно сгенерировать **новый**
`client_sale_id`.

Токен корзины — сам `client_sale_id`, а не общий для устройства: параллельные
продажи одного киоска не делят корзину и не перетирают чужие места. Повторный
ответ (200) восстанавливает `payment_url` из сохранённого платежа — иначе покупатель
не увидел бы ссылку на оплату после обрыва связи. Команда `kiosks:sync-sales`
повторно выбирает и статус `paid`, поэтому билет, выпущенный с задержкой, всё
равно получает QR.

## 5. QR и оплата

- QR подписывается **только на сервере** (`QrSigner`, формат
  `NB1.<publicId>.<token>.<sig>`) после webhook'а `payment.succeeded`.
- Секрет `TICKET_QR_SECRET` **не должен** попадать на киоск: утечка =
  возможность подписать любой билет. `QrSignerFactory` намеренно не имеет
  фолбэка на `APP_KEY`.
- Экран видит `qr_payload` только в статусе `issued`.
- Команда `kiosks:sync-sales` (каждую минуту) подтягивает оплату и выданные
  билеты в `kiosk_sales`. Это честно: пока webhook не пришёл, экран показывает
  «ожидание оплаты», а не «билет готов».

### Ограничение платёжного контура (важно)

`YooKassaProvider` создаёт платёж с `confirmation.type=redirect` — это
браузерный сценарий. На киоске с сенсорным экраном нужен встроенный платёжный
интерфейс (SDK эквайринга, СБП/QR-оплата) или кассовый узел, который дёргает
тот же защищённый webhook. Эндпоинт `/kiosk/sales` возвращает `payment_url`,
который киоск-клиент должен отобразить в своём встроенном компоненте.
Наличные, фискализация (54-ФЗ) и печать чека в системе пока отсутствуют —
это отдельная работа при реальном внедрении.

## 6. Офлайн-поведение

- Каталог и цены можно кешировать; продажи — **нет**. Сервер остаётся
  единственным источником решения о месте и билете.
- При потере связи киоску следует складывать в локальную очередь намерения с тем же
  `client_sale_id` и показывать квитанцию «ожидает подтверждения». Билет
  подтягивается после восстановления связи через `GET /kiosk/sales/{id}`.
- Нельзя подписывать QR офлайн: для этого нужен серверный секрет.
- `last_seen_at` пишется heartbeat'ом; считается «на связи», если он в пределах
  `nabilet.kiosks.offline_after_minutes` (по умолчанию 5).

## 7. Конфигурация

```php
// config/nabilet.php
'kiosks' => [
    'enabled' => env('NABILET_KIOSKS_ENABLED', true),      // глобальный kill switch
    'offline_after_minutes' => env('NABILET_KIOSK_OFFLINE_MINUTES', 5),
    'default_sales_enabled' => false,                      // новый киоск НЕ продаёт
],
```

`NABILET_KIOSKS_ENABLED=false` снимает продажи со всех киосков, не отзывая
токены и не ломая heartbeat/обновления.

## 8. Безопасность

- Минимальные полномочия: токен киоска даёт доступ только к `/kiosk/*`,
  организация жёстко привязана к устройству; PAT сотрудника и embed-ключ
  киоску **не выдаются**.
- Хеш токена `CHAR(64)`, токен показывается только при создании/ротации.
- Отзыв и ротация немедленно разрывают доступ (fail-closed `status !== active`).
- Чужой киоск/сеанс/продажа — 404, без утечки существования.
- Идемпотентность продажи и платежа на уровне БД и детерминированного ключа.
- QR отсылается только тому же авторизованному устройству в момент продажи.

## 9. Проверки

```powershell
php tools/verify-migrations.php
php tools/verify-models-schema.php
php tools/verify-module-structure.php
php tools/verify-route-ownership.php
php tests/run.php
php vendor/bin/phpunit
npm run typecheck
npm test
```

Тесты:

- `tests/Unit/KioskPolicyTest.php` — статусы, fail-closed, переходы продажи;
- `tests/Feature/Kiosk/KioskDeviceTest.php` — CRUD, переключатель продаж, токены,
  аналитика, удалённое обновление, кросс-тенант;
- `tests/Feature/Kiosk/KioskSaleTest.php` — продажа, реплей по `client_sale_id`,
  отключённые продажи, синхронизация QR.

## 10. Что осталось вне скоупа

- POS/эквайринг, СБП, наличные, фискализация и печать чека (54-ФЗ).
- Предварительно подписанные QR-пакеты для офлайн-выдачи (сейчас продажа создаётся только онлайн).
- `qr_ttl` в конфиге не вшит в payload `NB1`; при необходимости — версия `NB2`
  с `exp` и обновление чекера.
- DB CHECK на словари статусов: добавление CHECK, не описанного в
  `nabilet_core_spec/`, ломает `verify-migrations`; словари пока охраняются
  доменом и валидацией контроллеров.
