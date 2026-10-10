# Углублённый аудит оплаты и возвратов — Nabilet

**Дата:** 2026-10-10
**Аудитор:** специалист по платёжным системам и финансовой безопасности
**Объём:** `app/Modules/Payments`, `app/Modules/Webhooks`, `app/Modules/Orders` (OrderService, OrderStateMachine, StaleOrderExpirer), `app/Modules/Events` (EventPublicationService/Policy), `config/nabilet.php`, миграции (`database/migrations/2026_09_20_000500_005_payments_tickets.php`, `..._000800_008_integrations_system.php`), тесты (`tests/Feature/Payments/PaymentWritePathTest.php`, `tests/Unit/PaymentSettlementTest.php`).
Реальные платежи/возвраты не выполнялись, секреты не использовались, код не изменялся.

---

## 0. Фактическая реализация провайдера (без доверия документации)

YooKassa **реально интегрирована** — не только в доке:

* `app/Modules/Payments/Providers/YooKassaProvider.php` — HTTP-клиент к `https://api.yookassa.ru/v3` (Laravel `Http` facade + Basic Auth shopId/secretKey):
  * `createPayment()` (стр. ~120–165): POST `/payments`, `capture: true` (авто-захват), confirmation type `redirect`, заголовок `Idempotence-Key`;
  * `getPayment()` (стр. 166–191): GET `/payments/{id}` — **реализован, но нигде не вызывается** (см. риск R-10);
  * `refund()` (стр. 193–227): POST `/refunds`.
* Конфигурация: `config/nabilet.php` стр. 124–161 — `PAYMENT_PROVIDERS`, `YOOKASSA_SHOP_ID`, `YOOKASSA_SECRET_KEY`, `YOOKASSA_WEBHOOK_SECRET`, `YOOKASSA_WEBHOOK_IP_ALLOWLIST`, `PAYMENT_DEMO_MODE`, `default_provider = 'yookassa'`.
* Есть также demo-режим (локальный симулятор, `demo_*` payment ids, endpoint `POST /api/v1/payments/demo-pay`) — на проде должен быть выключен (контроллер честно отвечает 403 `DEMO_DISABLED`, см. `PaymentController::demoPay`).

Другие провайдеры (Stripe, Kaspi) присутствуют только как ветки верификатора подписей; реальных HTTP-вызовов нет.

---

## 1–16. Проверка по пунктам задания

### 1. Создание платежа и привязка к заказу
`PaymentController::store` → `PaymentService::initiatePayment(orderId, ...)`: сумма берётся из `orders.total_amount` (серверное значение), `order_id` пишется в `payments.order_id` (FK с `ON DELETE CASCADE`, `migrations.sql` L513). Привязка корректна. **Замечание:** статус заказа при создании платежа **не проверяется** — можно инициировать платёж для `cancelled`/`expired` заказа (см. R-05).

### 2. Проверка суммы, валюты, идентификатора заказа
При создании — OK (server-side amount/currency из заказа; `currency ?? 'RUB'`).
В вебхуке — **НЕ ПРОВЕРЯЕТСЯ**: `processWebhook` (`PaymentService.php` L247–332) читает из payload только `object.id` и `event`; поля `amount`/`currency`/`metadata.order_id` из уведомления не сверяются вообще. Готовый доменный проверочный слой `PaymentSettlement`/`SettlementRequest` (сравнение сумм, валют, запрет воскрешения терминальных заказов) существует в `app/Modules/Payments/Domain/` и покрыт юнит-тестом `tests/Unit/PaymentSettlementTest.php`, но **не подключён к `processWebhook`** (проверено grep: ни одного вызова вне Domain/). → **CRITICAL R-01**.

### 3. Серверная сумма вместо данных браузера
OK. В запросе `POST /payments` клиент передаёт только `order_id` и опционально `idempotency_key` (`PaymentController::store`, validate-правила L96–108); сумма никогда не принимается из тела запроса. Повторно: в вебхуке серверная сумма используется для транзакции (`addTransaction(['amount' => $payment->amount])`), но входящая сумма из payload игнорируется даже для сверки (R-01).

### 4. Обработка уведомлений провайдера
Маршруты: `POST /api/v1/payments/webhooks/{provider}` и алиас `POST /api/v1/webhooks/payment/{provider}` (`app/Modules/Payments/routes/api.php` L32–36); дубль-обработчик в модуле Webhooks (`app/Modules/Webhooks/routes/api.php` L15). Обрабатываются `payment.succeeded`, `payment.failed`, `payment.canceled`; неизвестный тип → исключение → откат транзакции (корректно: событие не помечается обработанным). `payment.waiting_for_capture` маппится провайдером в `payment.failed` (`YooKassaProvider::handleWebhook` L253) — семантически неверно, хотя при `capture:true` почти не достижимо.

### 5. Проверка подлинности уведомлений
Двухслойная схема `WebhookAuthenticator`: IP-allowlist + опциональный HMAC-SHA256 (`WebhookSignatureVerifier`). Fail-closed при пустой конфигурации (422). Проблемы:
* `in_array('*', $allowlist, true)` — литерал `*` открывает allowlist для любого IP (R-04);
* HMAC считается по **перекодированному** JSON (`$request->json()->all()` → `json_encode(..., JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`), а не по сырому телу запроса — любое расхождение канонизации ломает подпись у честного отправителя и создаёт неоднозначность для атакующего (R-04a);
* `YooKassaProvider::verifySignature` и `WebhookSignatureVerifier::getProviderSecret` читают `config('payments.*')` / `config('payments.yookassa_webhook_secret')`, а реальный секрет лежит в `nabilet.payment.yookassa.webhook_secret` (`config/nabilet.php` L156). Файла `config/payments.php` в проекте **нет** → эти ветки всегда получают `null`/пусто: мёртвый код с ложным чувством защиты (R-04b);
* ЮKassa действительно не подписывает вебхуки HMAC — единственный механизм провайдера (IP allowlist) реализован, но требует корректной настройки `trusted proxies`, иначе `$request->ip()` после CDN/LB = IP балансировщика (проверить настройку перед продакшеном).

### 6. Повторная доставка одного уведомления
Хорошо: таблица `webhook_events` с `UNIQUE (provider, provider_event_id)` (миграция 008, L67–78) + атомарный `insertOrIgnore` claim внутри транзакции (L292–303); повтор → 0 строк → ранний выход без побочных эффектов. Машина платежей отвергает `succeeded→succeeded` (`PaymentStateMachine`: терминальные состояния без исходящих переходов), `applyPayment` идемпотентен при `order.status === PAID`. Тест есть: `test_a_replayed_webhook_does_not_pay_twice`.
Дефект: fallback `event_id = paymentId . ':' . eventType` (L279) — если провайдер пришлёт два разных легитимных события с одинаковой парой (крайне редкость) или изменится spelling — возможны коллизии; приемлемо, но стоит логировать collision.

### 7. Повторные и параллельные запросы на оплату
Последовательно — OK: проверка существующего pending/waiting_for_capture платежа на заказ (L115–124) + `UNIQUE (provider, idempotency_key)` + конфликт ключа для чужого заказа (409 IDEMPOTENCY_CONFLICT, L100–112).
**Параллельно — гонка (R-02):** два одновременных `POST /payments` с разными ключами оба проходят SELECT-проверку «нет активного платежа» (нет `lockForUpdate` на заказ/платежи), оба создают платёж у провайдера и обе строки `payments` вставляются (разные idempotency_key нарушают unique не могут). Итог: два активных confirmation_url на один заказ → потенциальное двойное списание при оплате обоих; `applyPayment` переведёт заказ в paid по сумме ≥ total, второй succeeded-вебхук найдёт платёж по своему provider_payment_id и тоже станет `succeeded` — деньги приняты дважды, заказ оплачен один раз, второй платёж никто автоматически не вернёт.

### 8. Сопоставление статусов платежа и заказа
Машины согласованы: создание платежа → `pending→awaiting_payment`; `payment.succeeded` → `awaiting_payment→paid` (через `applyPayment`, с допуском `pending→awaiting→paid`); `failed/canceled` → `payment_failed` (ретрай разрешён); refund → `paid→partially_refunded|refunded` через `RefundLedger`. Разрывы:
* `expired`/`cancelled` — терминальные, но вебхук `succeeded` их **не блокирует** (R-05): платёж станет `succeeded`, заказ останется `expired` (машина запрещает переход) → деньги есть, билета нет;
* `markInventorySoldForOrder` пишет `status='sold'` прямым `update()` мимо `InventoryItemStateMachine` (L404–413) — обход guard-rails;
* прямой `update(['status' => ...])` в `handlePaymentCanceled` (L524) вместо repository/machine — работает, но минует единый путь записи.

### 9. Отмена платежа и истечение срока ожидания
`payment.canceled` обрабатывается отдельно от `failed` (A12, комментарий L509+), заказ → `payment_failed`, места возвращает sweeper. Истечение холда при оплате защищено `validateHoldsForOrder` + grace-окном `HoldGrace` (общее для sweeper/expirer/webhook) — хорошо. Но: `StaleOrderExpirer` закрывает заказы со статусами `pending/awaiting_payment/payment_failed` с **незакрытыми (pending) платежами** — платёж остаётся живым у провайдера; если покупатель завершит оплату позже, см. R-05. Таймаута самого платежа на стороне приложения нет (полагается на TTL ЮKassa 22 часа) — reconcile отсутствует (R-10).

### 10. Полные и частичные возвраты
`RefundService::refund`: только `succeeded`-платежи; учёт уже возвращённого (`sum(amount) where status != failed`), отказ при превышении остатка, машина `requested→processing→succeeded|failed`, аннуляция билетов при полном возврате. Логика верная.
**Критично:** метод **не экспонирован ни одним HTTP-маршрут** — `grep` по `routes/` и контроллерам: ни `POST /payments/{id}/refund`, ни админского экшена возврата нет; `PaymentService::refundPayment` — делегат без вызывающих мест (кроме тестов). Фактически система **не умеет делать возвраты из интерфейса/API** (R-06). Также: `RefundStateMachine::SUCCEEDED` никогда не выставляется — возврат навсегда остаётся `processing`, потому что событие `refund.succeeded` из вебхука не обрабатывается (`processWebhook` его не знает → бросает «Unknown webhook event type» → 500 на каждое штатное уведомление ЮKassa о возврате!) (R-07). При этом `totalRefunded` считает такие зависшие `processing` как зачтённые — повторный возврат правомерно блокируется, но статус возврата и `completed_at` недостоверны.

### 11. Отмена мероприятия и массовые возвраты
`EventPublicationService::cancel` **запрещает** отмену при наличии проданных единиц (`EventPublicationPolicy::cancelDecision`, HAS_SOLD_UNITS — «refunds must be settled first»). Это честная защита от «билеты аннулированы раньше, чем деньги возвращены», но механизма settle-first **нет**: ни batch-refund команды, ни job'ы, ни API. Оператор, которому нужно отменить проданное мероприятие, заперт: обход возможен только прямой правкой БД (вне машин состояний) → **R-08 (functional gap, высокий приоритет)**.

### 12. Защита от повторного возврата одной суммы
На уровне бизнес-логики — OK (проверка `totalRefunded + refundAmount > payment.amount`).
На уровне конкурентности — **нет блокировки**: `refund()` не делает `SELECT ... FOR UPDATE` на платёж/строки refunds; два параллельных полного возврата прочитают одинаковый `totalRefunded = 0` и оба пройдут проверку → переплата (R-03). Плюс `YooKassaProvider::refund` шлёт `Idempotence-Key = uniqid('ykr_', true)` (L198) — уникальный на каждый вызов, т.е. даже повтор запроса после таймаута сеть↔провайдер будет воспринят ЮKassa как новый возврат → второй возврат денег на провайдерской стороне (R-03b). Идемпотентный ключ для возврата должен выводиться из `refund.public_id`.

### 13. Сверка платежей с заказами
Автоматической сверки (reconciliation) **нет**: `getPayment()` провайдера никем не вызывается; консольных команд `payments:reconcile`/аналог нет; `webhook_events.processed_at IS NULL` индексируется, но никто по нему не выбирает зависшие/непроцессированные события. Потерянное уведомление (простой сети, 5xx на нашей стороне, рестарт) = «деньги взяты, заказ не оплачен» навсегда, обнаруживается только вручную. **R-10.**

### 14. Журналирование финансовых операций
Есть `payment_transactions` (authorization/capture/failure/refund) с payload; `webhook_events` хранит полный payload; логи `Log::info/error`. Пробелы: `provider_event_id` в `payment_transactions` не заполняется (unique `(payment_id, provider_event_id)` с NULL не защищает от дублей транзакций); audit_logs к платежам не пишутся (grep пуст); в `YooKassaProvider` ошибки провайдера логируются, но тело успешных ответов — нет (для расследований пригодился бы redacted snapshot). **R-11 (средний).**

### 15. Защита от подмены идентификаторов и сумм
Идентификаторы: `resolveOrder` различает ULID `public_id` и чисто цифровический id (защита от MySQL-коэрции `'1abc'→1`), `whereNumber` на GET /payments/{id}, fail-closed скоупы списка, `mayPay()` (владелец/сотрудник/cart-token) — хорошо. Подтверждение демо-платежа принимает **только** `provider_payment_id` с префиксом `demo_` (чужой id не подставишь) — приемлемо при выключенном на проде demo_mode.
Суммы: главная дыра — отсутствие сверки суммы в вебхуке (R-01): злоумышленник, получивший возможность слать запросы с разрешённого IP (или при allowlist `*` — с любого), может подтвердить платёж на любую сумму, и система примет это как оплату; обратная подмена (payload с меньшей суммой)также проходит молча — заказ становится `paid`, хотя провайдер взял меньше → **убыток**. Доказательство: `processWebhook` L247–332 — `$payload['object']['amount']` не читается нигде в пути обработки.

### 16. Неопределённый результат запроса к платёжному API
* `createPayment` у провайдера выполняется **внутри** DB-транзакции `initiatePayment` (L94–149): сетевой таймаут → откат БД, но платёж у ЮKassa мог создаться (external side effect inside transaction, rollback не откатывает внешний мир). Последующий retry с новым idempotency_key создаст второй платёж у провайдера → риск двойного списания (усиление R-02). Корректный паттерн: сначала создать запись `payments` со стабильным ключом (committed), затем вызывать провайдера с тем же `Idempotence-Key`, потом финализировать; либо reconcile по ключу.
* `RefundService::refund`: при исключении HTTP-клиента (таймаут = неопределённый исход, а не «не принято») возврат помечается `failed` и бросается наружу — при этом ЮKassa мог принять возврат. Повтор разрешён машиной (`failed→processing`) с новым `uniqid()` ключом → **второй фактический возврат** (R-03/R-03b). Нужен детерминированный Idempotence-Key + GET-уточнение статуса возврата перед ретраем.
* Обработка ответа: `number_format($amount/100, 2)` и обратно `(int) round($value*100)` — копейки конвертируются корректно; базовая валюта minor units едина.

---

## Таблица допустимых переходов (фактическая, из кода)

### Payment (`PaymentStateMachine`)
| Из \ В | pending | waiting_for_capture | succeeded | canceled | failed |
|---|---|---|---|---|---|
| **pending** | – | ✅ | ✅ | ✅ | ✅ |
| **waiting_for_capture** | ❌ | – | ✅ | ✅ | ✅ |
| **succeeded** (терминальный) | ❌ | ❌ | ↻ replay (ok) | ❌ | ❌ |
| **canceled** (терминальный) | ❌ | ❌ | ❌ | ↻ | ❌ |
| **failed** (терминальный) | ❌ | ❌ | ❌ | ❌ | ↻ |

### Refund (`RefundStateMachine`)
`requested → processing → succeeded | failed`; `failed → processing` (ретраj). ⚠️ `processing → succeeded` недостижим в рантайме: обработчика `refund.succeeded` нет (R-07).

### Order (`OrderStateMachine`)
| Из \ В | pending | awaiting_payment | paid | partially_refunded | refunded | cancelled | expired | payment_failed |
|---|---|---|---|---|---|---|---|---|
| **pending** | – | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ | ✅ |
| **awaiting_payment** | ❌ | – | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ |
| **paid** | ❌ | ❌ | ↻ | ✅ | ✅ | ❌ | ❌ | ❌ |
| **partially_refunded** | ❌ | ❌ | ❌ | – | ✅ | ❌ | ❌ | ❌ |
| **refunded / cancelled / expired** | терминальные | | | | | | | |
| **payment_failed** | ❌ | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ | – |

### Проблемные связки «платёж × заказ»

| # | Комбинация | Исход в текущем коде | Вердикт |
|---|---|---|---|
| T1 | order=`expired`/`cancelled` + webhook `payment.succeeded` | payment → `succeeded`; заказ остаётся terminal; `applyPayment` молча не двигает; билет **не выпускается**; инвентарь уже перепродан | ❌ **ПОТЕРЯ ПОДТВЕРЖДЁННОЙ ОПЛАТЫ** — деньги есть, обязательства нет, автоматического возврата нет (R-05) |
| T2 | Два payment `pending` на одном заказе (параллельный старт) + оба оплачены | оба → `succeeded`; заказ `paid` один раз | ❌ **ДВОЙНОЕ СПИСАНИЕ**, второй платёж оседает без возврата (R-02) |
| T3 | Два параллельных `refund(полный)` | оба пройдут проверку остатка (нет lock) | ❌ **ДВОЙНОЙ ВОЗВРАТ** / переплата (R-03) |
| T4 | Запрос возврата → таймаут сети → retry | refund `failed` → новый `processing` с новым Idempotence-Key | ❌ провайдер мог исполнить оба → двойной возврат (R-03b) |
| T5 | Webhook `succeeded` с суммой ≠ order.total | принимается без сверки | ❌ выдача билета за неоплаченную/недоплаченную сумму (R-01) |
| T6 | `payment.waiting_for_capture` (если включить capture=false) | провайдер-маппер превращает в `payment.failed` | ❌ переход невозможно обработать корректно: захваченные деньги помечаются отказом (сейчас смягчено capture=true) |
| T7 | `refund.succeeded`/`refund.failed` от провайдера | default-ветка → RuntimeException → HTTP 500, claim откатывается | ❌ вечные повторы провайдера; возврат зависает в `processing` (R-07) |
| T8 | order=`paid`, поздний webhook `payment.failed` по второму (дублирующему) платежу | переход заказа запрещён — ок; платёж `pending→failed` — ок | ✅ корректно |
| T9 | hold истёк ровно в момент succeeded | ConflictError SEAT_HOLDS_EXPIRED, транзакция откатана, событие НЕ заclaimлено → ретрай провайдера повторит попытку | ⚠️ почти ок: если холд так и не вернётся, провайдер будет ретраить вечно; нужен алерт/DLQ |

Переходы, которые **невозможно корректно обработать** сегодня: T1, T5, T6, T7. Риски двойного списания: T2, T4. Выдача неоплаченного билета: T5 (и косвенно T1-вариант «билет выпущен, деньги не те»). Потеря подтверждённой оплаты: T1, а также любое потерянное уведомление из-за отсутствия reconcile (R-10).

---

## Перечень финансовых рисков (с доказательствами и приоритетом)

| ID | Риск | Доказательство в коде | Приоритет |
|---|---|---|---|
| R-01 | Вебхук `succeeded` принимается **без сверки суммы/валюты/order_id**; готовый `PaymentSettlement` не подключён | `PaymentService::processWebhook` L247–332 (payload.amount не читается); `Payments/Domain/PaymentSettlement.php` — 0 вызовов вне Domain | **P0** |
| R-02 | Гонка параллельных `POST /payments`: два активных платежа на заказ → двойное списание | `initiatePayment` L114–124: SELECT без `lockForUpdate`; different idempotency keys обходят unique | **P1** |
| R-03 | Гонка параллельных возвратов → возврат больше уплаченного | `RefundService::refund` L52+: sum(refunds) без блокировки платежа/строк | **P1** |
| R-03b | Возвраты без детерминированного Idempotence-Key (`uniqid()`) → двойной возврат при ретрае после таймаута | `YooKassaProvider::refund` L198 | **P1** |
| R-04 | Allowlist поддерживает wildcard `*` (любой IP); HMAC считается по перекодированному JSON, не по raw body | `WebhookAuthenticator` L33; `WebhookSignatureVerifier::verifyYooKassa` | **P1** (конфиг-гайд + код) |
| R-04b | Мёртвые конфиг-пути секретов `config('payments.*')` vs фактический `nabilet.payment.yookassa.webhook_secret`; `config/payments.php` отсутствует | `WebhookSignatureVerifier::getProviderSecret`, `YooKassaProvider::verifySignature` | P2 |
| R-05 | Оплата просроченного/отменённого заказа: деньги берутся, билет не выпускается, автоматического возврата нет; статус заказа не проверяется при initiate | `handlePaymentSucceeded` L334+ (order terminal не отсекается), `initiatePayment` (нет проверки `order.status`) | **P1** |
| R-06 | **Нет HTTP-доступа к возвратам** — `refundPayment` не вызывается из контроллеров/роутов/админки | grep по routes/ и app/: только делегат и тесты | **P1** (operational) |
| R-07 | `refund.succeeded`/`refund.failed` не обрабатываются → 500 на каждое уведомление ЮKassa о возврате; возвраты зависают в `processing`, `completed_at` не ставится | `processWebhook` switch L305–323; `YooKassaProvider::handleWebhook` генерирует эти события | **P1** |
| R-08 | Нет массовых возвратов при отмене мероприятия; отмена заблокирована политикой, обходного инструмента нет | `EventPublicationPolicy::cancelDecision` HAS_SOLD_UNITS; batch-refund отсутствует | **P1** (functional) |
| R-09 | Внешний вызов провайдеру внутри DB-транзакции; при таймауте платёж у ЮKassa живёт, в БД — нет | `initiatePayment` L94–149 | P2 |
| R-10 | Нет reconcile/polling: потерянный вебхук = «деньги есть — заказа нет» навсегда; `getPayment()` не вызывается; зависшие `webhook_events.processed_at IS NULL` никто не мониторит | grep getPayment/reconcile — пусто | **P1** |
| R-11 | `payment_transactions.provider_event_id` не заполняется → нет защиты от дублей записей журнала; audit_logs не пишутся | `addTransaction` вызовы без provider_event_id | P2 |
| R-12 | Прямые `update(['status'=>...])` мимо state machine (`markInventorySoldForOrder`, `handlePaymentCanceled`) | L404–413, L524 | P3 |
| R-13 | Demo-pay endpoint анонимен; защита — только флаг `demo_mode`; включение флага на проде = мгновенная фальшивая оплата | `PaymentController::demoPay` | P2 (ops-guardrail) |
| R-14 | `waiting_for_capture` отображается в `failed` адаптером (при переходе на двухшаговую схему — потеря захвата) | `YooKassaProvider::handleWebhook` match | P3 |

---

## Предлагаемый набор автоматизированных тестов

Инфраструктура: PHPUnit feature-тесты на SQLite/MySQL-тест-БД (паттерн уже есть — `PaymentWritePathTest`), `Http::fake()` для моков YooKassa v3, фикстуры заказа/холдов, отдельный набор интеграционных тестов против test-sandbox ЮKassa (тестовые ключи `test_shp_…`, никаких реальных средств).

**Создание платежа**
1. `test_initiate_payment_uses_server_side_amount_and_currency` — total_amount заказа попал в запрос провайдеру (assert Http record), тело клиента не влияет.
2. `test_concurrent_payment_initiation_creates_single_active_payment` — два параллельных запроса (fork/concurl или последовательный вызов с имитацией interleaving через DB events) → ровно один активный платёж (ловит R-02 после фикса lockForUpdate).
3. `test_idempotency_key_reuse_for_foreign_order_returns_409` (уже есть частично).
4. `test_payment_cannot_be_initiated_for_cancelled_or_expired_order` (R-05).
5. `test_provider_timeout_rolls_back_local_payment_but_retry_with_same_key_is_safe` (R-09: Idempotence-Key стабилен между retry).

**Вебхуки**
6. `test_webhook_succeeded_with_mismatched_amount_is_refused_and_alerted` — payload с amount±1 копейку → платёж не succeeded, заказ не paid, событие не помечено processed (ловит R-01).
7. `test_webhook_succeeded_with_wrong_currency_is_refused` (CURRENCY_MISMATCH из SettlementRequest).
8. `test_forged_webhook_without_signature_and_foreign_ip_gets_403` (все комбинации allowlist/secret матрицей).
9. `test_wildcard_allowlist_is_rejected_in_production_config` — config-guard тест (R-04).
10. `test_hmac_verification_against_raw_body_bytes` — payload с non-ASCII/слэшами: подпись по `file_get_contents('php://input')` проходит, по перекодированному — нет (фиксирует R-04a).
11. `test_duplicate_delivery_of_same_event_id_is_noop` (есть) + `test_two_concurrent_identical_webhooks_process_once` (гонка insertOrIgnore под параллелизмом).
12. `test_unknown_event_type_returns_200_ack_without_claim` / `test_payment_waiting_for_capture_maps_to_internal_state` (R-14).
13. `test_webhook_succeeded_for_terminal_order_creates_manual_refund_task` — ожидаемое поведение после фикса R-05 (сейчас тест упал бы — good red test).

**Возвраты**
14. `test_full_then_partial_refund_exceeding_balance_is_rejected` (есть частично) + `test_concurrent_full_refunds_only_one_succeeds` (R-03).
15. `test_refund_idempotence_key_derived_from_refund_public_id_and_stable_across_retry` (R-03b, assert Http::recorded() — одинаковый header дважды).
16. `test_refund_succeeded_webhook_finalizes_processing_refund_and_sets_completed_at` (R-07).
17. `test_refund_after_provider_timeout_queries_provider_before_marking_failed` (неопределённый исход → GET /refunds, а не слепой failed).
18. `test_full_refund_revokes_issued_tickets_and_blocks_checkin` (частично есть в A14 — расширить end-to-end через QR-скан).
19. `test_refund_endpoint_requires_staff_role_and_scopes_by_organization` (актуален после реализации R-06).

**Отмена мероприятия**
20. `test_event_cancel_with_sold_orders_triggers_batch_refund_jobs_per_payment` (R-08).
21. `test_batch_refund_is_resumable_and_skips_already_refunded_payments`.

**Сверка/журнал**
22. `test_reconcile_command_detects_provider_succeeded_vs_local_pending_and_settles_via_same_validated_path` (R-10; моки `Http::fake` со списком платежей).
23. `test_stale_unprocessed_webhook_events_are_surfaced_by_monitor_command`.
24. `test_every_financial_transition_writes_payment_transaction_with_provider_event_id` (R-11).
25. Property-based: инвариант «Σ capture − Σ refund_succeeded|processing ≥ 0 и ≤ amount» после произвольной последовательности операций (fast-check/собственный генератор).

**E2E в sandbox ЮKassa (manual/nightly, тестовые ключи)**
26. Полный цикл: создание → оплата тестовой картой → webhook (через tunnel) → билет → частичный возврат → полный возврат → check-in отклонён.

---

## Критерии безопасного запуска (production launch gate)

1. **P0 закрыт:** путь вебхука использует `PaymentSettlement` (сверка суммы/валюты/заказа, отказ терминальным заказам) либо равноценную проверку; тест №6 зелёный. До этого момента вебхук-эндпоинт должен быть выключен (feature flag) или работать только после GET-подтверждения статуса через `getPayment()`.
2. Конфигурация fail-closed: `YOOKASSA_WEBHOOK_IP_ALLOWLIST` = официальные CIDR ЮKassa, **без `*`**; guard-тест №9 в CI; проверена цепочка прокси (`trustedproxies`), `$request->ip()` = реальный источник.
3. `PAYMENT_DEMO_MODE=false` на проде + мониторинг-алерт на изменение; `config:show nabilet.payment` в деплой-пайплайне как step проверки.
4. Идемпотентность под нагрузкой: тесты №2, №11, №14 зелёные (блокировки/unique доказаны), Idempotence-Key возврата детерминирован (№15).
5. Возвраты доступны оператору (R-06) с RBAC и лимитами; `refund.succeeded/failed` обрабатываются (№16); зависших `processing` > N минут — алерт.
6. Reconcile-команда запускается по расписанию (каждые 5–15 мин) и закрывает кейсы «provider succeeded / local pending» и «lost webhook» (№22); очередь ручных разборов с метрикой времени разрешения.
7. SLO/алерты: доля refused-вебхуков, count `webhook_events.processed_at IS NULL > 10 min`, count terminal-order-with-succeeded-payment (>0 = paging).
8. Финансовая сверка D+1: Σ(`payments.status=succeeded`.amount) == Σ(`orders.paid`.total) == выписка ЮKassa; расхождения — блок на дальнейший релиз платёжного контура.
9. Все финансовые переходы проходят через state machines (нет прямых update status), журнал содержит provider_event_id (№24).
10. Нагрузочный прогон pay-flow (≥50 rps) без появления T1–T5 состояний в БД (инвариант-запросы к тестовой среде).

**Итог:** архитектура в целом здравая (терминальные машины, webhook_events-claim, server-side amounts, grace-окна), но три критических разрыва между имеющимися *готовыми* компонентами и их *использованием*: несвязанный `PaymentSettlement` с вебхук-путём (R-01), отсутствующий вход возврата и обработка refund-событий (R-06/R-07), отсутствие reconcile (R-10). Именно они, а не логика машин состояний, определяют финансовый риск системы сегодня.
