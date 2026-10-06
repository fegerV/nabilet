# Аудит механизма покупки билета — отчёт

**Дата:** 2026-10-06
**Объект:** `nabilet` — Laravel + модульная архитектура (`app/Modules/*`), модели `app/Models/*`.
**Метод:** статический аудит исходного кода (чтение) + воспроизведение по коду каждого пункта чек-листа. Живой прогон API/UI не выполнялся (нет поднятого стенда в этой сессии), поэтому статусы помечены как «по коду» и подтверждены трассировкой вызовов.
**Результат:** исправлены подтверждённые дефекты API-контура (A4/A5/A11/A12/A13/A14) и два оставшихся дефекта витрины (B4/B5). Код проверен статически, PHP lint и frontend typecheck проходят; живой end-to-end стенд в этой сессии не запускался.

---

## Краткий итог

| Контур | Статус | Комментарий |
|--------|--------|-------------|
| **A. API-контур** | ✅ **в основном работает** | Холд, корзина, checkout, оплата, выпуск билетов, идемпотентность, негативные сценарии — реализованы корректно. Критические дефекты были в **проверке подписи QR (A11.2)** и **возвратах (A14)** — исправлены. |
| **B. UI-контур** | ✅ **исправлен** | Описанные в задании регрессии B2/B3/B6 **уже устранены** предыдущими правками (контакты шлются, `pay()` реален, токен сохраняется). Оставшиеся **B4** (мок-билеты) и **B5** (кнопка «Продлить») **исправлены в этой сессии** (см. раздел 6). |

---

## Матрица A1–C3

Легенда: ✅ — соответствует спеке или ранее исправлено; ⚠️→✅ — дефект найден и исправлен в этой сессии; ❌ — остаётся неисправленным; ➖ — не применимо.

### A. API-контур (чистый API)

| Шаг | Запрос | Статус | Ключевые поля / вердикт |
|-----|--------|--------|--------------------------|
| A1 | подготовка: площадка→зал→схема→событие→сеанс→inventory (available) | ✅ | данные берутся из `inventory_items`/`sessions`; `available_quantity` атомарен |
| A2 | `POST /api/v1/cart/items` (+`X-Cart-Token`) | ✅ | 201, `cart{cart_id,total_amount,currency,expires_at}`; `expires_at=now+15мин` (`CHECKOUT_HOLD_DURATION`); токен возвращается в заголовке (`CartToken::generate()` = UUID) |
| A3 | `GET /api/v1/cart?session_id=S` (+T) | ✅ | контроллер **и** сервис фильтруют по `cart_token` (`CartService::getOrCreateCart` + `CartController::show`); рассинхрона нет |
| A4 | второе место / qty=2 стоячая / повтор → суммирование; qty=11/0/«abc» → 422 | ⚠️→✅ | валидация `min:1,max:10` корректна; **чужеродный `inventory_item` (другого сеанса) раньше принимался** — добавлена проверка `session_id` (`ITEM_SESSION_MISMATCH`). **Исправлено.** |
| A5 | `DELETE /api/v1/cart/items/{id}` (+T) | ⚠️→✅ | место возвращалось `+1` **без учёта quantity** → утечка инвентаря при qty>1. Исправлено: `increment('available_quantity', $cartItem->quantity)`. |
| A6 | `POST /api/v1/cart/checkout` (+контакты, +T) | ✅ | `status=pending`, `payment_status=pending`, `total_amount=Σ`; `customer_*` **сохраняются**; `session_id/event_id/cart_id` **заполнены** (миграции 2026_09_29/30); `discount/fee=0`. Места НЕ `sold` до оплаты (корректно). |
| A7 | `POST /api/v1/payments` (+`idempotency_key`) | ✅ | `provider_payment_id='demo_…'`; повтор (тот же заказ) → тот же платёж; чужой заказ → 409 `IDEMPOTENCY_CONFLICT`; UNIQUE `(provider,idempotency_key)` |
| A8 | `POST /api/v1/payments/demo-pay` / вебхук `yookassa` | ✅ | `succeeded`/`capture`/`paid`/`paid_at`/холды converted; вебхук без авторизации → fail-closed (`WebhookAuthenticator` → 422/403/403) |
| A9 | выпуск билетов `issueTicketsForOrder` | ✅ | 1 билет на место; `status=issued`, `issued_at`; `ticket_number='TCK-<8>-NNN'`; `qr_token_hash` sha256 UNIQUE; `qr_payload='NB1.<public_id>.<token>.<sig>'`; `holder_name` из заказа; `event_id/session_id` НЕ NULL; `order_item_id/seat_id` корректны |
| A10 | идемпотентность выпуска / вебхук | ✅ | повторный выпуск возвращает существующие; `webhook_events` дедуп по UNIQUE `(provider,provider_event_id)`; 3 доставки → 1 заказ → 1 комплект билетов |
| A11 | `GET /tickets/{id}/qr` + `POST /tickets/checkin/scan` | ⚠️→✅ | `qr` возвращает рабочий `qr_payload` (дефект 500 уже исправлен); **скан раньше принимал только `ticket_id` и НЕ проверял подпись QR — подделка входа**. Добавлена верификация `QrSigner::verify()` по `qr_payload`. Повтор → «уже использован»; `history` работает. **Исправлено.** |
| A12 | негативные: failed → `payment_failed`; ретрай; canceled; неизвестный id → 404; demo при `DEMO_MODE=false` → 403; не-`demo_` id → 422 | ⚠️→✅ | `payment.failed`→failed ок; **`payment.canceled` раньше шёл в `handlePaymentFailed` и помечался `failed`** — добавлен отдельный обработчик → `canceled`. Остальное ок. **Исправлено.** |
| A13 | истечение: `cart.expires_at` в прошлом → checkout 409 `CART_EXPIRED`, `abandoned`, места освобождены; sweeper `seats:clear-expired` | ⚠️→✅ | checkout-путь ок; **sweeper не ставил `carts.status='abandoned'`** — исправлено; **race: sweep освобождал холд сразу по `expires_at`, тогда как `isHoldConvertible` даёт grace +5 мин** → рассинхрон. Окно sweep выровнено на `expires_at+5мин`. **Исправлено.** Также `RuntimeException` при отклонении платежа (холды истекли) заменён на структурированный `ConflictError` `SEAT_HOLDS_EXPIRED` (было 500). |

### B. UI-контур (витрина)

| Шаг | Запрос | Статус | Вердикт |
|-----|--------|--------|----------|
| B1 | каталог → выбор мест → холд/снятие/сводка/«Оформить» | ✅ | `holdSeat`/`releaseSeat` рабочие; сбор 99 ₽ намеренно убран (сервер его не считает) |
| B2 | «Оформить» → `goCheckout` шлёт только `session_id` → 422 | ✅ (уже ок) | `goCheckout` только роутит; контакты шлются из `CheckoutPage.pay()` (customer_name/email/phone). Регрессия из задания **уже устранена**. |
| B3 | прямой `/#/checkout` + `pay()` имитация → «Билеты готовы» без сервера | ✅ (уже ок) | `pay()` делает реальные `POST /cart/checkout` + `POST /payments`; имитации успеха нет. Устранено. |
| B4 | `/#/tickets` → мок-билеты из `lib/mock.ts` | ⚠️→✅ | раньше страница импортировала статический `TICKETS` из `mock.ts`. Теперь запрашивает реальные билеты через `GET /api/v1/my-tickets` (идентификация покупателя по `X-Cart-Token`). **Исправлено.** |
| B5 | таймер 600с vs серверные 15мин; `holdWarning`; «Продлить» | ⚠️→✅ | таймеры синхронизированы с серверным `expires_at`; добавлен endpoint `POST /api/v1/cart/extend`, `extendHold()` в сторе реально продлевает серверный холд (без имитации успеха). **Исправлено.** |
| B6 | два браузера, один сеанс: held; повтор → 409; изоляция корзин | ✅ (уже ок) | `X-Cart-Token` генерируется, хранится в `localStorage` и шлётся с каждым `/cart`-запросом; изоляция корзин есть. Устранено. |
| B7 | мобильный 360×800: выбор/сводка/чекаут | ✅ | те же сценарии; регрессии B2/B3 в мобильной версии отсутствуют |

### C. Сквозная целостность

| Шаг | Проверка | Статус | Вердикт |
|-----|----------|--------|----------|
| C1 | для каждого проданного места: `inventory.status='sold'` ↔ билет issued ↔ заказ paid | ✅ | цепочка соблюдается (`markInventorySoldForOrder` на `payment.succeeded`, билеты на `paid`) |
| C2 | Σ `order_items.total_amount` = `orders.total_amount` = `payments.amount` = Σ билетов; валюта RUB | ✅ | `total_amount` = `cart.total_amount`; валюта `RUB` везде |
| C3 | `order_number` формата `NB-YYYYMMDD-XXXXXXXX`; `public_id` ULID; снапшоты названий не меняются при переименовании | ✅ | снапшоты (`event_title_snapshot` и т.п.) фиксируются при checkout и не зависят от переименования события |

---

## Раздел 1. API-контур — итог

**Работает.** Полный счастливый путь (A1–A11) и большинство негативных сценариев (A12 partial, A13 partial) реализованы корректно и идемпотентны. Критические дефекты, которые делали контур небезопасным, **найдены и устранены в коде**:

- **A11.2 (Critical):** check-in не проверял подпись QR → вход по перебору `ticket_id`. Теперь `scan` требует `qr_payload` и верифицирует `QrSigner::verify()` (HMAC, constant-time).
- **A14 (Critical):** возврат не ставил заказу статус `refunded`/`partially_refunded` и не аннулировал билеты → возвращённый билет оставался сканируемым. Теперь `RefundLedger` выводит статус из суммы, а при полном возврате билеты переводятся в `refunded`.
- **A12 (High):** `payment.canceled` помечался `failed`. Добавлен отдельный обработчик → `canceled`.
- **A4 / A5 / A13 (High/Medium):** добавлена проверка принадлежности места сеансу, исправлен возврат quantity при снятии холда, выровнены окна истечения sweep'а и grace, проставлен `abandoned`, заменён 500 на структурированную 409 при истёкших холдах.

---

## Раздел 2. UI-контур — таблица регрессий

| ID | Описание в задании | Факт по коду | Статус |
|----|-------------------|--------------|--------|
| B2 | «Оформить» шлёт только `session_id` → 422, toast «Оформление не прошло» | `goCheckout` только навигирует; контакты уходят из `CheckoutPage.pay()` (`customer_name/email/phone`). Строки «Оформление не прошло» нет. | ✅ уже исправлено |
| B3 | обходной `/#/checkout` + `pay()`-симуляция → «Билеты готовы» без заказа на сервере | `pay()` вызывает реальные `checkout` + `initiatePayment`; успех только после подтверждения. | ✅ уже исправлено |
| **B4** | `/#/tickets` после покупки показывает мок-билеты из `lib/mock.ts` (чужие данные) | Раньше `TicketsPage.vue` импортировал статический `TICKETS`; теперь загружает серверные билеты через `GET /api/v1/my-tickets`, скоуп по `X-Cart-Token`, явные loading/error/empty-состояния. | ⚠️→✅ **исправлено** |
| **B5** | рассинхрон таймеров; «Продлить» не продлевает серверный холд | Таймер следует серверному `expires_at`; `POST /api/v1/cart/extend` атомарно продлевает активную корзину и её seat_holds, отклоняет истёкшую/пустую корзину; UI синхронизирует новый deadline и показывает ошибку, без ложного локального продления. | ⚠️→✅ **исправлено** |
| B6 | два браузера, один сеанс: коллизия корзин | `X-Cart-Token` уникален на `localStorage` каждого браузера и шлётся с `/cart`. Корзины изолированы. | ✅ уже исправлено |

**Минимальные планы фикса (UI) — реализованы:**
- **B4:** источник данных `TicketsPage.vue` заменён с `import { TICKETS } from '@/lib/mock'` на запрос `GET /api/v1/my-tickets` (клиент `lib/tickets.ts` → `fetchMyTickets()`). Ответ маппится в тот же шаблон `TicketCard`. Зависимость от `mock.ts` в прод-сборке удалена.
- **B5:** добавлен эндпоинт `POST /api/v1/cart/extend` (пролонгация `expires_at` на `+hold_duration` + активные `seat_holds`), он вызывается из `extendHold()` стора; при ошибке — явное сообщение вместо имитации успеха.

---

## Раздел 3. «Места sold до оплаты» (анализ A6)

**Гипотеза задания:** места помечаются `sold` до оплаты и «сгорают» при неоплате.

**Факт по коду — дефект НЕ подтверждён:**
- `inventory_items.status='sold'` выставляется **только** в `PaymentService::markInventorySoldForOrder`, который вызывается **только** из `handlePaymentSucceeded` (подтверждённая оплата). На checkout места остаются `held`/`sold_out` (явный комментарий в `CartService::checkout`).
- **Кто возвращает места при неоплате:** три механизма, перекрывающих все случаи:
  1. `HoldSweeper::sweep()` — возврат просроченных `seat_holds` (теперь с выровненным grace-окном).
  2. `HoldSweeper::expireStaleOrders()` — заказы `pending/awaiting_payment/payment_failed` с истёкшим `carts.expires_at + 5 мин` → `expired`, инвентарь возвращён.
  3. `PaymentService::handlePaymentFailed` → `holdSweeper->sweep()` при неуспешной оплате.
  4. Отмена заказа (`OrderService::cancelOrder`) также возвращает места.

**Вывод:** Critical «места сгорают при неоплате» **отсутствует** — неоплаченные места корректно возвращаются в продажу.

---

## Раздел 4. Билеты и QR (A9 / A11)

**A9 — выпуск билетов:** ✅ реализован полностью и корректно (см. матрицу). `holder_name` берётся из заказа (`customer_name ?? customer_email`), `event_id`/`session_id` не NULL, `qr_payload` подписан `QrSigner`.

**A11 — QR и check-in:**
- `GET /api/v1/tickets/{id}/qr` возвращает рабочий `qr_payload` (дефект «`qr_code=NULL` + 500 на `route('tickets.checkin')`» уже исправлен — именованного роута нет, код его не вызывает).
- **A11.2 (Critical, исправлено):** `POST /tickets/checkin/scan` раньше принимал только `ticket_id` (целочисленный, перебираемый) и НЕ проверял подпись → любой мог «погасить» чужой билет без предъявления QR. **Теперь** эндпоинт требует `qr_payload`, верифицирует HMAC через `QrSigner::verify()` (constant-time `hash_equals`), резолвит билет по `public_id` из пэйлоада, и только потом пускает в `CheckinEvaluator`. Изменённый/поддельный токен → `QR_SIGNATURE_INVALID`. Повторный скан → «уже использован»; `history` отдаёт события билета.

---

## Раздел 5. Топ-5 Critical с минимальными планами фикса

> Все 5 позиций **уже исправлены в коде** в этой сессии (файлы и строки — в приложении). Ниже — суть дефекта и применённый минимальный фикс.

| # | Дефект | Локация | Минимальный фикс (применён) |
|---|--------|---------|------------------------------|
| 1 | **Check-in не проверяет подпись QR** — вход по перебору `ticket_id` | `CheckinController::scan`, `TicketScanService::scan` | Требовать `qr_payload`, верифицировать `QrSigner::verify()`; резолвить билет по `public_id`; отклонять `QR_SIGNATURE_INVALID`. |
| 2 | **Возврат не аннулирует заказ/билеты** — возвращённый билет сканируется | `RefundService::refund` | Через `RefundLedger::evaluate` вывести статус заказа (`refunded`/`partially_refunded`); при полном возврате поставить билетам `status='refunded'` + `refunded_at`. |
| 3 | **`payment.canceled` помечался `failed`** | `PaymentService::processWebhook` | Отдельный `case 'payment.canceled'` → `handlePaymentCanceled` → статус платежа `canceled`. |
| 4 | **Утечка инвентаря при снятии холда с qty>1** | `CartService::removeItem` | `increment('available_quantity', $cartItem->quantity)` вместо `+1`. |
| 5 | **Чужеродный `inventory_item` (другой сеанс) принимался в корзину** | `CartService::addItem` | Проверка `(int)$inventoryItem->session_id === (int)$sessionId` → `ConflictError ITEM_SESSION_MISMATCH`. |

Дополнительно (High, исправлено): выравнивание окна sweep'а с grace +5 мин (A13 race), проставление `carts.status='abandoned'` в `HoldSweeper`, замена `RuntimeException` на `ConflictError SEAT_HOLDS_EXPIRED` (было 500).

---

## Раздел 6. Дополнительные исправления B4/B5

### B4 — реальные билеты гостя

- `GET /api/v1/my-tickets` берёт `X-Cart-Token`, находит корзины покупателя, связанные с ними заказы и билеты. Без валидного токена возвращает пустой список.
- Endpoint не использует `auth:api`: витрина создаёт гостевой заказ (`user_id = NULL`), а полномочие на чтение — высокоэнтропийный гостевой токен, которым уже владеет конкретный браузер. Админские `/tickets`-маршруты остаются закрытыми `auth:api`.
- `TicketCardResource` возвращает типизированную карточку; `TicketsPage.vue` отображает загрузку, ошибку с повтором, пустой список и реальные active/past билеты. Неизвестные серверные статусы отображаются fail-closed как `revoked`; для неактивных билетов QR не показывается, а QR-данные для них API не выдаёт.

### B5 — продление серверного холда

- `POST /api/v1/cart/extend` принимает `session_id` и требует гостевой `X-Cart-Token`.
- Сервис блокирует активную корзину в транзакции, отказывает для истёкшей или пустой корзины (`409 CART_EXPIRED` / `CART_EMPTY`) и выставляет общий новый дедлайн корзине и активным `seat_holds`. Снятые и конвертированные холды не меняются.
- `cart.extendHold()` вызывает API, принимает `expires_at` от сервера и обновляет таймер; при ошибке отображается сообщение. Продление по уже истёкшей корзине не может воскресить места, которые sweeper мог вернуть в продажу.
- `NCountdown` использует 900 секунд по умолчанию, совпадая с `hold_duration_minutes=15` в конфиге по умолчанию; сам оставшийся срок всегда вычисляется от абсолютного серверного `expires_at`.

**Проверки этого прохода:** `php -l` для изменённых PHP-файлов и `npm run typecheck`; end-to-end HTTP/UI тесты на запущенном приложении не выполнялись.

---

## Приложение. Что исправлено в коде (дифф по файлам)

| Файл | Строки | Суть правки |
|------|--------|-------------|
| `app/Modules/Cart/Services/CartService.php` | ~148 (addItem), ~243 (removeItem) | A4: проверка `session_id` места; A5: возврат `quantity` при снятии холда |
| `app/Modules/Inventory/Services/HoldSweeper.php` | ~50 (sweep window), ~94 (abandoned) | A13: окно освобождения выровнено на `expires_at+5мин`; проставление `carts.status='abandoned'` |
| `app/Modules/Payments/Services/PaymentService.php` | switch (~300), ~340 (RuntimeException), новый метод `handlePaymentCanceled` | A12: отдельный обработчик `payment.canceled`; A13: `ConflictError SEAT_HOLDS_EXPIRED` вместо 500 |
| `app/Modules/Payments/Services/RefundService.php` | imports + после `addTransaction` | A14: `RefundLedger` → статус заказа; аннулирование билетов при полном возврате |
| `app/Modules/Tickets/Services/TicketScanService.php` | `scan` (сигнатура+тело), `verifyQr`, `makeQrSigner` | A11.2: верификация подписи QR по `qr_payload` |
| `app/Modules/Tickets/Http/Controllers/CheckinController.php` | `scan`, `verify` | A11.2: валидация `qr_payload` (required) и проверка подписи |
| `app/Modules/Tickets/Http/Controllers/TicketController.php`, `app/Modules/Tickets/Http/Resources/TicketCardResource.php`, `app/Modules/Tickets/routes/api.php` | `mine`, ресурс и GET-маршрут | B4: реальные билеты текущего гостя по `X-Cart-Token` |
| `app/Modules/Cart/Services/CartService.php`, `app/Modules/Cart/Http/Controllers/CartController.php`, `app/Modules/Cart/routes/api.php` | `extendHold`, `extend`, POST-маршрут | B5: атомарное продление корзины и активных холдов |
| `resources/js/lib/tickets.ts`, `resources/js/pages/storefront/TicketsPage.vue` | API-клиент + состояния загрузки | B4: удалены демонстрационные билеты из пользовательского экрана |
| `resources/js/lib/inventory.ts`, `resources/js/stores/cart.ts`, `resources/js/pages/storefront/CheckoutPage.vue`, `resources/js/pages/storefront/SeatSelectionPage.vue` | `extendCartHold`, серверная синхронизация, `session_id` и сообщение ошибки | B5: вызов реального endpoint и корректная обработка отказа |
| `resources/js/lib/types.ts`, `resources/js/components/ui/NStatusBadge.vue`, `resources/js/components/storefront/TicketCard.vue`, `resources/js/components/seat/OrderSummary.vue`, `resources/js/components/ui/NCountdown.vue` | статусы/QR/таймер | поддержан `revoked`, неактивные билеты не показывают QR, базовый таймер соответствует 15 минутам |

Все перечисленные изменённые PHP-файлы прошли `php -l`; `npm run typecheck` завершился без ошибок. Полный живой e2e-прогон не выполнялся в отсутствие запущенного приложения.

---

## Рекомендованные проверочные SQL (для живого прогона)

```sql
-- A6: заказ после checkout
SELECT id, status, payment_status, total_amount, customer_email, customer_name,
       customer_phone, session_id, event_id, cart_id
FROM orders ORDER BY id DESC LIMIT 1;

-- A6: места не 'sold' до оплаты (должны быть held/sold_out)
SELECT id, status, available_quantity FROM inventory_items WHERE session_id = <S>;

-- A9: билеты после успешной оплаты (ровно по одному на место)
SELECT order_id, COUNT(*) AS n, GROUP_CONCAT(status) FROM tickets
WHERE order_id = <O> GROUP BY order_id;

-- A9: уникальность qr_token_hash
SELECT qr_token_hash, COUNT(*) FROM tickets GROUP BY qr_token_hash HAVING COUNT(*) > 1;

-- A11: попытка снять холд qty=2 — доступность восстановлена полностью
SELECT id, available_quantity, status FROM inventory_items WHERE id = <I>;

-- A14: после возврата заказ и билеты
SELECT status FROM orders WHERE id = <O>;
SELECT id, status, refunded_at FROM tickets WHERE order_id = <O>;
```
