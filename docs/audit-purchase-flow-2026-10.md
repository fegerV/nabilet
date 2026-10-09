# Сквозной QA-аудит бизнес-процесса покупки билета (Nabilet)

- **Дата аудита:** 10.10.2026
- **Роль:** QA Lead (e-commerce / билетные системы)
- **Метод:** статический аудит исходного кода (`/workspace`, ветка `main`) + анализ тестового набора (`tests/`). Код не изменялся.
- **Что НЕ проверялось (честно):** реальные интеграции с YooKassa, SMTP и очередями не выполнялись — нет секретов и тестового окружения провайдера. Все выводы по этим контурам основаны на коде; они помечены как «не верифицировано в живом окружении». Feature-тесты требуют живую MySQL и в рамках аудита не запускались (в репозитории есть `vendor/bin/phpunit`; запуск требует БД).

---

## 1. Архитектура цепочки покупки (фактическая реализация)

| Этап | Где реализовано | Данные на выходе | Проверка прав | Ошибка |
|---|---|---|---|---|
| Страница мероприятия | SPA: `resources/js/pages/storefront/EventPage.vue` (роут `/event/:slug`), SEO-роут `routes/web.php` → `app/Modules/Events/routes/web.php`; конфиг витрины `GET /api/v1/storefront` (`StorefrontController::show`, публичный) | event_id, session_id, slug | Публичный доступ; мультитенантность через поддомен/OrganizationContext | 503 STORE_FRONT_NOT_BUILT если SPA не собран |
| Выбор сеанса | `EventPage.vue` → данные из Events/Sessions API (`app/Modules/Sessions/routes/api.php`) | session_id | Публичное чтение | — |
| Выбор места/билета | `SeatSelectionPage.vue` + `useSeatInventory.ts`; данные `GET /api/v1/inventory/sessions/{id}/availability` (публичный); холд создаётся при первом добавлении: `POST /api/v1/cart/items` → `CartItemService::addItem()` | inventory_item_id, quantity; серверный `expires_at` | Гостевой capability `X-Cart-Token` (D5); sales-gate `Session::isSellableAt()` на каждом добавлении | 409 SEAT_UNAVAILABLE / CART_EXPIRED / ITEM_SESSION_MISMATCH, 422 SALES_CLOSED |
| Тариф/цена | Цена всегда серверная: `inventory_items.price_amount` читается с `lockForUpdate()` в `addItem()`; фронт хранит только намерение (инвариант «фронт — не источник истины») | unit_price, total_price | — | — |
| Скидка (промокод) | `PromoCodeService::evaluate()` внутри транзакции checkout (`CartCheckoutService::checkout()`, строки ~107–124); эндпоинты `app/Modules/Pricing/routes/api.php` | discount_amount; redemption пишется `redeem()` после создания заказа | Валидность окна/лимита; per_user_limit только для аутентифицированных | 422 INVALID_PROMO_CODE (с machine-readable details) |
| Корзина | `GET /api/v1/cart` (`CartController::show`), ключ (session_id, cart_token); TTL `nabilet.checkout.hold_duration_minutes` (15 мин) | items, total, expires_at | Тот же токен; гость без токена получает новый | data:null вместо 404 |
| Создание заказа | `POST /api/v1/cart/checkout` → `CartCheckoutService::checkout()` — одна `DB::transaction`: заказ + позиции + расход промокода + корзина→converted | order public_id (ULID), total_amount | Токен корзины обязателен (ValidationError если пусто) | 409 CART_EXPIRED (с сохранённой очисткой инвентаря), 422 CART_EMPTY, 409 SALES_CLOSED |
| Резервирование места | Материализуется сразу при addItem: `seat_holds` (A13) + атомарный `decrement available_quantity` c `lockForUpdate()`; инвариант Σhold = cart_item | seat_hold.expires_at = cart.expires_at | — | откат транзакции при любом throw |
| Переход к оплате | `POST /api/v1/payments` (**вне auth:api**, гостевой сценарий) → `PaymentController::store` → `PaymentService::initiatePayment()`; провайдер `YooKassaProvider` (demo-режим → локальный симулятор `#/checkout/demo-pay`) | payment, confirmation_url | `mayPay()`: сотрудник / владелец по user_id / гость с X-Cart-Token корзины заказа; чужой заказ → 404 | 409 IDEMPOTENCY_CONFLICT при переиспользовании ключа на другом заказе |
| Подтверждение оплаты | `POST /api/v1/payments/webhooks/{provider}` (+алиас `/webhooks/payment/{provider}`) → `WebhookAuthenticator` (fail-closed IP/секрет) → `PaymentService::processWebhook()` | payment.succeeded → markAsSucceeded → OrderService::applyPayment (paid) → holds converted → inventory sold → `TicketService::issueTicketsForOrder()` — всё в одной транзакции вебхука | Дедупликация через UNIQUE (provider, provider_event_id) в `webhook_events` | 409 SEAT_HOLDS_EXPIRED если холды истекли; unknown event → rollback claim, ретрай возможен |
| Выпуск билета | `TicketService::issueTicketsForOrder()` — идемпотентен (повтор возвращает существующие); QR `NB1.<publicId>.<token>.<sig>` (QrSigner, HMAC ≥32 байт) | tickets[] c qr_payload | Только status=paid | RuntimeException если заказ не paid (см. дефект D-7) |
| Отправка подтверждения | `OrderObserver::updated()` → `SendOrderNotificationJob` + `DispatchOrderWebhookJob`, оба `afterCommit()`, очередь connection `database`, tries=3, backoff [60,300] | письмо на customer_email по шаблону ticket.purchased | Сверка expectedStatus при исполнении — расхождение ⇒ молча не отправлять | Сбой SMTP → retry очереди; исчерп попыток → failed jobs |
| Личный кабинет | Авторизованные: `GET /api/v1/orders`, `GET /api/v1/tickets` (auth:api, принудительный скоуп user_id). Гости: `GET /api/v1/my-tickets` по X-Cart-Token (только UUID-длинные токены ≥32) | TicketCardResource: номер, QR, место | Чужой билет/заказ → 404 (fail-closed) | пустой список без токена |
| Проверка на входе | `POST /api/v1/tickets/checkin/scan|verify` (**auth:api + admin**) → `CheckinController` → `TicketScanService::scan()`; решение — чистый `CheckinEvaluator`; офлайн-пакеты — `scanFromBundle()` + reconciliation §44 | ticket used/already_used/refused | Подпись QR обязательна (A11.2); lockForUpdate на билете | QR_SIGNATURE_INVALID / WRONG_SESSION / ALREADY_USED |

### Атомарные операции (как реализовано / как должно быть)
Уже атомарны (подтверждено по коду):
1. `addItem`: decrement инвентаря + cart_item + seat_hold + статус места — одна транзакция с pessimistic locks.
2. `checkout`: заказ + items + promo redeem + конверсия корзины; throw откатывает всё, включая расход кода.
3. `processWebhook`: claim события (insert-or-ignore по UNIQUE) + payment succeeded + order paid + holds converted + inventory sold + выпуск билетов — одна транзакция.
4. `TicketScanService::scan`: verify подписи + lock билета + запись скана + issued→used — одна транзакция (повторный параллельный скан гасится lockForUpdate).
5. `HoldSweeper`: перечитывание холда с блокировкой внутри транзакции + условный UPDATE `markAsConverted` (affected=0 у проигравшего).

Требуется усилить (см. раздел дефектов): атомарность «идемпотентный возврат existing-payment» vs вызов провайдера (п. 2 негативных сценариев), атомарность проверки остатка промокода (check-then-act).

### Права пользователя (резюме модели)
- Гость: capability-токен корзины `X-Cart-Token` (UUID, нормализация `CartToken::normalize()`); даёт: корзина, checkout, оплата своего заказа, «мои билеты».
- Аутентифицированный: owner-scope по `orders.user_id`; принудительный фильтр в контроллерах Orders/Tickets/Payments («если не задан» заменён на форсированный).
- Staff (admin/manager): `StaffRole::isStaff`, tenant-скоуп через организацию; чекин — `auth:api + admin`.
- Чужой ресурс везде отдаёт **404, не 403** (анти-IDOR enumeration) — консистентно в PaymentController/TicketController/OrderController.
- Известная архитектурная слабость: гостевой заказ имеет `user_id=NULL`, поэтому «мои билеты» авторизованного пользователя, купившего как гость, не показываются (скоупы не объединены).

---

## 2. Негативные сценарии: факт / ожидание / расхождение

### S1. Обновление страницы (F5) во время оформления
- **Факт:** выбор мест восстанавливается из серверной корзины (`cart.ts::hydrate()` из `GET /api/v1/cart`), таймер живёт по серверному `expires_at` (C1). Заказ, созданный checkout, переживает полную навигацию через `sessionStorage['nabilet_last_order']` (id+сумма) — страница результата после возврата с шлюза показывает корректные данные. Холды продлеваются реальным `POST /cart/extend` (B5).
- **Ожидалось:** то же.
- **Расхождения:** (a) восстановление не работает, если вкладка закрыта и открыта заново (sessionStorage живёт до конца вкладки) — заказ теряется из UI, хотя на сервере жив; (b) приватный режим Safari (storage исключён из CORS-ответов, см. комментарий в `api.ts`) может ронять токен между навигациями — риск подтверждён только комментарием кода, не проверен в браузере. **Частично OK.**

### S2. Двойное нажатие кнопки оплаты
- **Факт:** сервер идемпотентен: `initiatePayment()` внутри транзакции ищет активный платёж заказа (pending/waiting_for_capture) и возвращает его же с тем же `confirmation_url`; повторный POST нового платежа не создаёт. На клиенте (`CheckoutPage.vue`/`payments.ts`) генерация `idempotency_key` и блокировка кнопки **не обнаружены** (grep по idempot/disabled не дал попаданий в страницах оформления).
- **Ожидалось:** клиент дебаунсит/дизейблит кнопку и передаёт стабильный idempotency_key.
- **Расхождение:** низкого риска на бэке, но UI может дважды открыть одну ссылку подтверждения; при реальном шлюзе это безопасно лишь потому, что провайдер принимает один pending-платёж на заказ. **OK на сервере, нет защиты на клиенте.**

### S3. Платёж прошёл, но браузер закрылся
- **Факт:** единственный путь выпуска билетов — webhook `payment.succeeded` (`handlePaymentSucceeded` → applyPayment → sold → issueTicketsForOrder, всё в одной транзакции). Закрытие браузера на него не влияет. Письмо уходит по смене статуса через afterCommit-джобу. Возврат в UI позже: «мои билеты» по токену/аккаунту.
- **Ожидалось:** то же + **polling/reconcile статуса платежа у провайдера** на случай потери webhook.
- **Расхождение:** **active-polling отсутствует**. `YooKassaProvider::getPayment()` реализован, но ни один контроллер/команда его не вызывает (проверено grep'ом — вызовов нет). Если YooKassa не доставит webhook (или доставка упадёт навсегда), деньги списаны, заказ вечен в awaiting_payment, а StaleOrderExpirer переведёт его в expired и вернёт места в продажу при оплаченном платеже. **КРИТИЧЕСКИЙ РИСК приёма платежей.**

### S4. Вернулся с платёжной страницы без подтверждения оплаты
- **Факт:** `PaymentResultPage.vue` при отсутствии order id честно шлёт payment_fail (нет имитации успеха); статус берётся с сервера. Заказ остаётся awaiting_payment, холды живут до sweeper'а/grace.
- **Ожидалось:** показать «ожидание платежа» + периодическую проверку статуса.
- **Расхождение:** страница результата не опрашивает `GET /api/v1/payments/{id}` повторно — покупатель, вернувшийся до webhook, видит «не оплачено» даже при уже прошедшей оплате (webhook обычно быстрее, но не гарантирован). Нет CTA «продлить бронь». **Средний риск UX/поддержки.**

### S5. Оплата завершилась ошибкой
- **Факт:** `payment.failed` → markAsFailed + failure transaction + заказ payment_failed (можно ретрай другой картой: `payment_failed → awaiting_payment` разрешён машиной) + немедленный `holdSweeper->sweep()` для возврата мест. `payment.canceled` — отдельный терминальный статус платежа A12, заказ тоже уходит в payment_failed. Демо/UI редирект на результат с ошибкой.
- **Ожидалось:** то же.
- **Расхождения:** minor — при отказе машины состояний переход молча пропускается (нет алерта); сообщение о причине покупателю зависит от шаблона письма payment_failed. **OK.**

### S6. Платёж подтверждён с задержкой (в пределах grace)
- **Факт:** согласованное grace-окно из единого источника `HoldGrace` (`CHECKOUT_HOLD_GRACE_MINUTES`, дефолт 5 мин): sweeper снимает холд только после `expires_at + grace` (cutoff), вебхук конвертирует пока `isWithinGrace`. Раньше эти две оценки расходились (дефект «оплата в последнюю секунду») — исправлено, закреплено `HoldGraceTest` + `test_expired_cart_returns_every_seat_it_reserved`. Для легаси-заказов без холдов — фолбэк на carts.expires_at + то же окно.
- **Ожидалось:** детерминированное поведение в окне grace.
- **Расхождение:** тонкая гонка осталась: `isHoldConvertible()` читает холд **без lockForUpdate**, а sweeper обновляет его в своей транзакции — при пересечении моментов возможна ложная конвертация/отклонение (окно миллисекунд, но на высоконагруженных продажах реально). Требуется `...lockForUpdate()` в чтении или условный UPDATE-as-check. **Средний риск.**

### S7. Место успело освободиться до подтверждения платежа
- **Факт:** `validateHoldsForOrder()` detects released/expired hold → webhook бросает ConflictError 409 SEAT_HOLDS_EXPIRED → транзакция откатывается (payment остаётся pending, заказ не paid, деньги не «подтверждены»), событие НЕ отмечается processed (claim откатан) → YooKassa будет ретраить и снова получить 409. Место возвращается в продажу; второй покупатель может его купить.
- **Ожидалось:** платёж списан ⇒ система обязана либо автоматически вернуть деньги (auto-refund), либо завести дело на ручной возврат, либо дать покупателю выбрать другое место в рамках той же суммы.
- **Расхождение:** **автоматического возврата нет**; заявка на возврат не создаётся; order не переходит ни в какой статус, требующий возврата; покупателю ничего не приходит (OrderObserver не срабатывает — статус не менялся). Деньги зависают в payment=succeeded-у-провайдера / pending-у-нас. **КРИТИЧЕСКИЙ ДЕФЕКТ (финансовый/юридический).**

### S8. Повторная отправка запроса создания заказа (double checkout)
- **Факт:** первый checkout помечает корзину `converted`; повторный POST → `firstOrFail()` по active-корзине → ModelNotFoundException → 404. Конкурентный двойной POST serialized by row-lock на корзине. Идемпотентность «верни существующий заказ» нет.
- **Ожидалось:** 200/201 с тем же order_id (idempotent replay), чтобы retry клиента/плохая сеть не пугали покупателя 404.
- **Расхождение:** корректности данных нет (двойного заказа не возникает), но контракт ответа недружелюбен; UI обязан трактовать CART-not-found после timeout как «уточни состояние», а он этого не делает. **Низкий/средний риск.**

### S9. Письмо с билетом не удалось отправить
- **Факт:** `SendOrderNotificationJob` в очереди `database`, tries=3, backoff 60/300 сек, afterCommit (не уходит при откате оплаты); при расхождении статуса — молча не отправляется. Билет при этом выпущен и доступен в «Моих билетах» (my-tickets / GET /tickets/{id}/qr). Повторная отправка — только вручную из failed jobs.
- **Ожидалось:** dead-letter + уведомление поддержки/авто-респам после восстановления; гарантия доставки хотя бы одного канала.
- **Расхождение:** после 3 неудач письмо просто оседает в failed_jobs; повторный триггер перехода paid→paid невозможен (wasChanged('status') false) — автоматического re-send нет. Данные билета не теряются. **Средний риск.** Не верифицировано с живым SMTP.

### S10. Билет пытаются использовать повторно
- **Факт:** `scan()` — DB-транзакция + `lockForUpdate` на билете; CheckinEvaluator: used → `already_used` (не ошибка, показывается original used_at — §32); revoked/refunded/cancelled/expired → refuse с причиной; каждый скан пишется в ticket_scans (аудит). Подделка по integer-id закрыта обязательной проверкой подписи QR (A11.2). Параллельные сканы двух турникетов сериализуются локом. Офлайн-ретрансляция: дедуп по (device_id, client_scan_id) + conflict→revoke (§44).
- **Ожидалось:** ровно это.
- **Расхождение:** нет. **OK** (закреплено `CheckinEvaluatorTest`, `OfflineBundleTest`; интеграционный тест гонок скана отсутствует — см. план).

### S11. Администратор отменил мероприятие после продажи билетов
- **Факт:** публикация/снятие мероприятия (`EventPublicationWritePathTest`) управляет видимостью и sales-gate (`isSellableAt` блокирует новые корзины/addItem/checkout после снятия с продажи). Однако **нет каскада на оплаченные заказы**: отзывы билетов, массовых возвратов, уведомлений «отменено» при cancel events не обнаружено (RefundService работает поштучно, payment-уровень; событийной команды «cancel event → refund+revoke all tickets» нет).
- **Ожидалось:** процедура отмены мероприятия: блокировать оплату незакрытых заказов, отозвать билеты (revoked), инициировать возвраты, разослать уведомления.
- **Расхождение:** процесс отсутствует целиком; билеты остаются issued со валидным QR на несостоявшееся мероприятие, деньги не возвращаются. **Высокий операционный/юридический риск.**

### S12. Покупатель пытается получить чужой билет
- **Факт:** `GET /tickets/{id}`, `/qr`, `/history` — auth:api + `assertCanSee` (владелец через order.user_id или staff), чужой → 404; числовой id с `whereNumber` (1abc больше не резолвится в 1); `my-tickets` без токена/с коротким токеном → пустой список; чекин закрыт staff-сессией. Закреплено `TicketApiTest` (endpoint exists) и OrderWritePathTest «cannot read another customers order».
- **Расхождение (важное):** гостевой владелец `order.user_id=NULL` — для него защита держится **только** на X-Cart-Token (capability). Утечка токена из localStorage (XSS) = полный доступ к билетам и праву оплаты; ротации/хешения токена нет (хранится как есть, сравнивается как есть). Acceptable-risk для гостевого UX, но должно быть в threat-model. **OK формально, замечание по модели угроз.**

---

## 3. Тестовое покрытие цепочки (что есть / чего нет)

**Есть (Unit, исполняются `php tests/run.php` без БД):** StateMachine/DomainStateMachine, HoldGrace, Money, QrSigner/QrEncoder golden master, CheckinEvaluator, TicketIssuance, TicketRevocation, OfflineBundle, IdempotencyPolicy, PaymentSettlement, OrderPlacement/OrderPricing, PromoEvaluatorCheckoutBridge, InventoryStock, SessionSeating/Lifecycle, SecurityPolicy, ErrorModel/Envelope, RetryPolicy, HealthProbe, cartStore.test.ts (фронт).

**Есть (Feature, требуют живую MySQL, в CI не запускались — запуск не верифицирован в этой среде):** CartWritePathTest (резерв/холд/истечение/чужое место/remove/extend/checkout/пустая), OrderWritePathTest (создание/резерв/цена-с-сервера/отмена/paid/идемпотентность applyPayment/tenant-leak), PaymentWritePathTest (schema/awaiting/auth-trans/idempotency-key/webhook succeeded/replay/failed-metadata/canceled/unknown/refund-guard/webhook-auth allowlist/fail-closed index), Notifications (TransactionalMail, OrderStatusNotification, OrderReminder, MailSettings), Webhooks outbound delivery, Tickets email template, Api smoke (TicketApiTest/OrderApiTest/EventApiTest — только «endpoint exists»).

**Пробелы (нет ни unit, ни feature):**
1. E2E-сценарий сквозной покупки guest-flow (seat→cart→checkout→pay→ticket→my-tickets) — ни одного теста полной цепочки.
2. Параллельная конкуренция за одно место (two buyers, race) — логика есть в коде, тестов конкурентности нет.
3. Double-click оплаты / повтор initiatePayment при существующем pending — частично покрыто idempotency-key тестом, но не «два клика одним клиентом».
4. Webhook при истёкшем холде (SEAT_HOLDS_EXPIRED) и судьба денег после отказа — нет теста.
5. Восстановление после потери webhook (polling/reconcile) — функционал отсутствует, теста нет.
6. Гонка sweeper × webhook в окне grace — нет стресс-теста.
7. Отмена мероприятия с проданными билетами — нет процесса и теста.
8. Failed-job replay писем — нет.
9. Реальный HTTP-контракт YooKassa (signature/формат payload) — mock-верификация невозможна без песочницы; **чека на подлинность payload YooKassa по факту нет** (см. D-5).
10. Фронт: Playwright/Cypress отсутствуют полностью.

---

## 4. Дефекты, приоритизированные для приёма РЕАЛЬНЫХ платежей

| # | Приоритет | Дефект | Место |
|---|---|---|---|
| D-1 | **Blocker** | Нет сверки статуса платежа у провайдера (polling/reconcile job). Потеря webhook = деньги списаны, билет не выпущен, заказ затем expire'ится и место перепродаётся. `getPayment()` мёртвый код. | Payments (нет consumers у `YooKassaProvider::getPayment`) |
| D-2 | **Blocker** | SEAT_HOLDS_EXPIRED при позднем succeeded: платёж отклоняется без авто-возврата и без заявки на возврат; деньги зависают; повторная доставка webhook зацикливается на 409. | `PaymentService::handlePaymentSucceeded` / validateHoldsForOrder |
| D-3 | **Blocker** | Fail-closed webhook-авторизация при **отсутствии конфигурации** ломает сам канал подтверждения оплаты в проде (422 WEBHOOK_NOT_AUTHENTICATED, пока не задан allowlist/секрет); при этом YooKassa HMAC не подписывает — секретная ветка нерабочая, остаётся только IP-allowlist, который легко сломать без trust proxies. Плюс `YooKassaProvider::verifySignature` читает `config('payments.yookassa_webhook_secret')` — ключ не существует (правильный путь `nabilet.payment.*`), вторая, «тихая» проверка подписи мертва. | WebhookAuthenticator, config/nabilet.php, YooKassaProvider::verifySignature |
| D-4 | High | Нет процедуры отмены мероприятия: билеты остаются валидными, возвраты не инициируются, уведомления не рассылаются (S11). | Events/Sessions lifecycle ↔ Orders/Tickets |
| D-5 | High | `isHoldConvertible()` читает холд без блокировки — теоретическая гонка с sweeper'ом в последнюю секунду grace (S6). | SeatHoldLifecycle |
| D-6 | High | Промокод: check-then-act (`isRedeemable()` → `redeem()`) — счётчик используется условным UPDATE внутри транзакции, но лимиты window/per_user проверялись до блока; при конкурентных checkout возможен перевыпуск скидки сверх limit_total (нужен тест-подтверждение; по коду redeem защищён частично). | PromoCodeService |
| D-7 | Medium | `issueTicketsForOrder` бросает `RuntimeException` для не-paid заказа; при повторной доставке succeeded после уже-paid это не происходит (can() short-circuit), но прямой вызов из админ-пути даст 500 вместо доменной ошибки. | TicketService |
| D-8 | Medium | Checkout без токена/после timeout отдаёт 404 вместо идемпотентного возврата существующего заказа (S8); UI не трактует это specially. | CartCheckoutService |
| D-9 | Medium | Guest-token = bearer capability без ротации/хеширования; XSS в витрине = кража заказов и QR (S12). | CartToken / api.ts |
| D-10 | Medium | Письмо после 3 фейлов оседает в failed_jobs без повторного триггера и без эскалации (S9). | SendOrderNotificationJob |
| D-11 | Low | Клиентский таймер HOLD_SECONDS=600 в store — легаси-страховка расходится с серверными 15 мин (используется только когда сервер не прислал expires_at). | cart.ts |
| D-12 | Low | `/ping` отвечает 200 при мёртвой БД (мониторинг должен смотреть /health) — документировано, но риск эксплуатационный. | routes/api.php |

Положительное: класс ошибок/AppError-конверт, state machines с guard'ами, единый HoldGrace, идемпотентность webhook через UNIQUE claim, pessimistic locking в критических путях, fail-closed скоупы чтения, где-то с явными тестами — архитектура зрелая; перечисленные блокеры — это дыры контура интеграций, а не базовой логики.

---

## 5. План автоматизации тестов (приоритет)

**P0 (до включения реальных платежей):**
1. Feature: «full guest purchase» happy path через HTTP API (demo_mode): availability → add item → extend → checkout → POST payments → webhook succeeded → assert order paid, inventory sold, tickets issued, notification job queued.
2. Feature: «lost webhook reconcile» — после внедрения polling-джобы: payment на провайдере succeeded, webhook не приходил → job выставляет заказ/билеты; и обратное: provider says failed → заказ payment_failed.
3. Feature: поздний succeeded при истёкшем холде → ожидаемое поведение после фикса D-2 (auto-refund record created, money not stuck).
4. Feature: webhook authentication matrix: no config → 422; allowlist hit/miss; secret valid/invalid — плюс integration-test против захардкоженного fixture YooKassa-notify payload (формат `event/object.id`).
5.并发 (MySQL): два addItem на последний экземпляр места → ровно один 201, один 409; два checkout одновременно → один заказ; double POST payments → один payment.
6. Config test: `nabilet.payment.yookassa.webhook_ip_allowlist` non-empty в env-примере деплоя + alert при пустом (CI-проверка `.env.production.example`).

**P1:**
7. Event cancellation cascade test (после реализации D-4): revoke tickets + refunds + notifications.
8. Promo limits concurrency (limit_total=1, два checkout) — закрывает D-6.
9. Notification failure: transport failure → retries → manual resend endpoint (после D-10).
10. Playwright guest journey (happy + S1 refresh + S4 return-without-pay), 3 сценария.
11. Race stress: sweeper vs webhook в границах grace (time-travel тесты с Carbon::setTestNow).

**P2:**
12. Contract-тесты OpenAPI↔implementation для cart/payments/tickets.
13. Mutation-testing критических модулей (StateMachine, HoldGrace, CheckinEvaluator).
14. Yandex.Checkout sandbox smoke (ручный чек-лист → автоматизация при наличии тестовых ключей).

---

## 6. Ограничения аудита

- Реальные вызовы YooKassa/SMTP не выполнялись (нет секретов/песочницы) — утверждения об их поведении основаны на коде и документах провайдера; статус «не верифицировано в живом окружении».
- Feature-тесты не запускались (требуется MySQL); их наличие и заявленные проверки взяты из текста тестов.
- Поведение фронта в браузере (S1 storage quirks) не проверялось — нет e2e-инфраструктуры.

*Файл создан в рамках аудита; код не изменялся.*
