# Аудит архитектуры и модели данных Nabilet

**Дата:** 2026-10-10
**Статус:** Независимый диагностический отчёт. Файлы не изменялись.
**Основано на:** Код `/workspace` (Laravel-монолит, ~35 модулей), миграции `database/migrations/*.php`, эталонная схема `nabilet_core_spec`.

---

## 1. Карта архитектуры по фактическому коду

### Слои и границы
*   **Ядро (`app/Core`):** Реализует `StateMachine`, идемпотентность (`IdempotencyPolicy`, middleware `EnsureIdempotency`), типизированные ошибки (`AppError`), поддержку мультитенантности и единый источник времени блокировок (`HoldGrace`).
*   **Модули (`app/Modules/*`):** 35 доменных модулей (Cart, Orders, Payments, Tickets, Inventory и др.). Внутри каждого: `Http / Services / Domain / StateMachines / Repositories / Models / routes`.
*   **Плоские модели (`app/Models`):** Дублируют модульные Eloquent-модели, создавая риск рассинхронизации логики.

### Ключевой поток продажи
1.  `CartItemService::addItem`: Создание резерва (hold) и decrement инвентаря.
2.  `CartCheckoutService::checkout`: Конвертация корзины в заказ.
3.  `PaymentService::initiatePayment`: Инициация платежа.
4.  Вебхук `processWebhook` → `handlePaymentSucceeded`: Подтверждение оплаты.
5.  `OrderService::applyPayment`: Статус заказа → paid.
6.  `markInventorySoldForOrder` → `TicketService::issueTicketsForOrder`: Выпуск билетов.

**Фоновые процессы:**
*   `HoldSweeper`: Освобождение просроченных холдов.
*   `StaleOrderExpirer`: Закрытие «зомби»-заказов.
*   `OrderObserver`: Уведомления через очередь (`afterCommit`).

### Нарушения границ модулей (Дефекты)
*   **Циклическая зависимость Cart ↔ Orders:** `StaleOrderExpirer` (Orders) импортирует `CartItemService` (Cart). `OrderService::cancelOrder` напрямую взаимодействует с `SeatHold`.
*   **Кросс-модульная логика:** `CartCheckoutService` напрямую создает записи `Order/OrderItem`, минуя сервисы модуля Orders.
*   **Дублирование сущностей:** Разные классы `Session` в модулях Events и Sessions; разные классы `SeatHold` в Modules и Orders; наличие устаревших доменов (`Superseded`) рядом с рабочим кодом.

---

## 2. ER-модель существующей базы данных

```mermaid
erDiagram
    organizations ||--o{ events : has
    events ||--o{ sessions : contains
    venues ||--o{ halls : has
    halls ||--o{ hall_schema_versions : versions
    hall_schema_versions ||--o{ sectors : defines
    sectors ||--o{ hall_rows : contains
    hall_rows ||--o{ seats : contains
    sectors ||--o{ standing_zones : contains

    sessions ||--o{ inventory_items : tracks_stock
    inventory_items }|--|| seats : references_seat
    inventory_items }|--|| standing_zones : references_zone

    carts ||--o{ cart_items : contains
    carts ||--o{ seat_holds : reserves
    seat_holds }|--|| inventory_items : holds

    orders ||--o{ order_items : contains
    orders ||--o{ payments : billed_by
    payments ||--o{ payment_transactions : logs
    payments ||--o{ refunds : refunded_by
    orders ||--o{ tickets : issues
    tickets ||--o{ ticket_scans : scanned_at

    orders }|--|| carts : converted_from (NO FK!)
    orders }|--|| sessions : for_session (NO FK!)
    orders }|--|| events : for_event (NO FK!)

    promo_codes ||--o{ promo_code_redemptions : redeemed_in
    webhook_events ||--o{ payments : triggers
```

**Примечание:** Связи `orders.cart_id`, `orders.session_id`, `orders.event_id` отсутствуют как внешние ключи (см. §3). Таблица `event_dates` существует, но не связана с основным потоком продаж.

---

## 3. Целостность данных: Ключи, Индексы, Правила удаления

### Реальные дефекты схемы
1.  **Отсутствие FK для заказов:** В `orders` нет внешних ключей на `carts.id`, `sessions.id`, `events.id` (миграции `2026_09_29_000100`, `2026_09_30_000100`). Это допускает появление сирот и усложняет очистку данных.
2.  **Противоречивая стратегия удаления:**
    *   `payments.order_id` и `refunds.order_id` имеют `ON DELETE CASCADE`. Удаление заказа уничтожает финансовую историю платежей и возвратов. Рекомендуется `RESTRICT`.
    *   `promo_codes.event_id` имеет `CASCADE`. Жесткое удаление события удаляет связанные промокоды.
3.  **Типизация цен:** `cart_items.unit_price` хранится как BIGINT (minor units), но сервис возвращает строки, что может привести к регрессии типов при строгих настройках PHP.
4.  **Деградация CHECK-констрейнтов:** Миграция `2026_09_20_001000` пропускает установку ограничений на SQLite без явной ошибки статуса ("Ran"). На нестандартных драйверах БД проверки статусов и неотрицательности денег могут отсутствовать.
5.  **Молчаливый отказ триггеров immutability:** Использование `safeUnprepared()` в `2026_10_06_000100` скрывает ошибки создания триггеров (например, на shared hosting), оставляя версии схем залов незащищенными от изменений.
6.  **Дублирование цены:** Цена может быть определена на уровне `hall_rows`, `seats` и `inventory_items` без правил согласованности между ними.

### Корректные ограничения (Позитив)
*   `uq_inventory_session_seat`: Гарантирует уникальность места в рамках сеанса (главный защитник от двойной продажи).
*   `uq_payments_idempotency`, `uq_webhook_events_provider_id`: Обеспечивают идемпотентность платежей и вебхуков.
*   `uq_carts_active`: Использует generated column для обеспечения одной активной корзины на токен/сеанс.

---

## 4. Деньги, Валюта, Округление

**Реализация:**
*   Все суммы хранятся как integer minor units (BIGINT).
*   Скидки рассчитываются через базисные пункты (string-math), округление half-up выполняется целочисленно (`intdiv`).
*   Цены фиксируются (snapshot) в `cart_items` и `order_items` на момент покупки.

**Дефекты и Риски:**
1.  **Float в отчетах:** `getRevenueReport()` конвертирует деньги во float, что недопустимо для финансовых отчетов.
2.  **Смешанная валюта:** При добавлении товара в корзину (`addItem`) не проверяется совпадение валюты `inventory_item` и `cart`. Место в RUB можно добавить в корзину KZT без пересчета.
3.  **Fee Amount:** Поле всегда 0 при checkout, сборы не интегрированы в модель расчета итога.
4.  **Refund Parity:** Логика частичного возврата не гарантирует кратности сумм относительно исходных позиций заказа.

---

## 5. Жизненные циклы и Смешение понятий

**Машины состояний реализованы корректно:**
*   **Заказ:** pending → awaiting_payment → paid → refunded/expired/cancelled.
*   **Платеж:** pending → succeeded/failed/canceled.
*   **Билет:** issued → used/cancelled/refunded/revoked.
*   **Холд:** active → released/converted.

**Анализ смешения понятий:**
*   **Разделение:** Заказ, Платеж, Билет и Бронирование (Hold) разделены в схеме БД корректно (отдельные таблицы, связи 1:N).
*   **Двойственность бронирования:** Резерв реализуется двумя способами одновременно:
    1.  Изменение счетчика `available_quantity` в `inventory_items`.
    2.  Запись в таблице `seat_holds`.
    *   **Дефект:** Инвариант `Σ hold.quantity == Δ available_quantity` не защищен на уровне БД. Исторические баги утечки мест исправлялись кодом (`growHold`), а не схемой.
*   **Дублирование статусов:** Статус наличия мест дублируется в `inventory_items.status` (available/held/sold), `orders.status` и наличии записей в `seat_holds`. Рассинхрон возможен (например, `sold` ставится всем позициям заказа, даже если это стоячая зона с остатком capacity).

---

## 6–7. Транзакции и Конкурентная продажа (Моделирование)

**Сценарий:** Два пользователя (A и B) пытаются купить последнее место (available=1).

**Основной путь (Cart API):**
1.  Оба входят в транзакцию.
2.  `CartItemService::addItem` выполняет атомарный SQL:
    ```sql
    UPDATE inventory_items SET available_quantity = available_quantity - 1
    WHERE id = ? AND available_quantity >= 1 FOR UPDATE;
    ```
3.  Транзакция A получает X-lock, списывает место (1→0), коммитит.
4.  Транзакция B ждет lock. После коммита A перечитывает строку (semi-consistent read), условие `0 >= 1` ложно, affected rows = 0.
5.  Приложение выбрасывает `ConflictError` (409).

**Вывод:** Для основного пути единственность продажи **гарантирована** на уровне БД благодаря атомарному условному UPDATE и уникальному индексу `uq_inventory_session_seat`.

**Дефекты конкурентности:**
1.  **D1 (High): Admin Path Race Condition.** `OrderService::createOrder` (админка) использует паттерн "check-then-decrement" без `lockForUpdate`. Между чтением доступности и списанием есть окно гонки. Возможна двойная продажа или падение с ошибкой CHECK constraint (500 вместо 409). Этот путь также не создает `seat_holds`, затрудняя освобождение мест при отмене.
2.  **D3 (Medium): Deadlocks.** Порядок блокировки ресурсов различается в `addItem` (cart → inventory → holds), `checkout` (cart → inventory → order) и `sweeper` (holds → inventory). Отсутствие retry-логики на deadlock (MySQL 1213) приводит к 500 ошибкам для клиентов.
3.  **D2 (Medium): Missing Unique Ticket Index.** В таблице `tickets` нет `UNIQUE(session_id, seat_id)`. Защита зависит только от логики приложения. Любая ошибка в коде выпуска может создать два билета на одно место.

---

## 8. TTL блокировок и Освобождение резервов

**Механизм:**
*   Единое окно grace: `Core/Support/HoldGrace` (дефолт 5 мин).
*   Sweeper запускается каждую минуту, освобождает истекшие холды и закрывает stale-заказы.
*   Идемпотентность обработки гарантирована повторным чтением с `FOR UPDATE` внутри транзакции sweep.

**Критический Дефект (D5 - High):**
*   **Вечный ретрай вебхука при истекшем холде.** Если платеж успешно подтвержден провайдером, но холд уже истек (или был освобожден sweeper'ом), `handlePaymentSucceeded` бросает исключение `SEAT_HOLDS_EXPIRED`.
*   Это исключение происходит **внутри транзакции**, которая также пытается отметить событие вебхука как обработанное (`claim`).
*   Транзакция откатывается полностью, включая claim. Провайдер считает, что событие не обработано, и бесконечно ретраит его.
*   **Результат:** Деньги списаны, билет не выпущен, система перегружена повторными запросами. Требуется терминальный путь обработки (авто-refund или ручное вмешательство) без отката claim.

---

## 9. Повторные запросы и Идемпототентность

*   **HTTP Idempotency Middleware:** Зарегистрирован в `bootstrap/app.php`, но **не применен ни к одному маршруту** в модулях. POST-запросы на checkout и платежи не защищены на уровне HTTP.
*   **Сервисная идемпотентность:**
    *   `initiatePayment` идемпотентен благодаря `UNIQUE(provider, idempotency_key)` и проверке активного платежа.
    *   Вебхуки идемпотентны благодаря `UNIQUE(provider, provider_event_id)` и машине состояний.
*   **Риск:** Двойной клик по кнопке "Оплатить" без нового токена может привести к созданию второго заказа, если первая корзина уже конвертирована (поведение зависит от реализации `firstOrFail` vs создание новой). Текущая защита достаточна, но декларативно неполна.

---

## 10. Расширяемость системы

*   **Версионирование схем залов:** Реализовано хорошо (`hall_schema_versions`, immutable триггеры, freeze published версий). Инвентарь привязан к версии геометрии.
*   **Проблемы расширения:**
    1.  **X1 (High): Drift Schema.** Есть две версии "источника истины": `database/migrations` и `nabilet_core_spec/migrations.sql`. Плюс архивные папки. Риск расхождения структуры БД и ожиданий кода.
    2.  **X2 (Medium): Осиротевшая сущность `event_dates`.** Дублирует семантику `sessions`, используется только в плоских моделях. Препятствует унификации расписания.
    3.  **X3 (Medium): Ad-hoc колонки.** Добавление колонок в `orders` без FK и CHECK (см. §3) нарушает принцип целостности при масштабировании.

---

## Сводный список нарушений и Приоритеты

| ID | Описание | Файл/Локация | Severity | Тип |
|----|----------|--------------|----------|-----|
| D5 | Вечный ретрай вебхука при истекшем холде (потеря денег/билета) | `Payments/Services/PaymentService.php` | **High** | Дефект логики |
| D1 | Гонка данных в админском создании заказа (no lock, no holds) | `Orders/Services/OrderService.php` | **High** | Дефект конкурентности |
| X1 | Расхождение источников истины схемы БД | `migrations.sql` vs `database/migrations` | **High** | Архитектура |
| D2 | Отсутствие UNIQUE на билеты по месту | `tickets` table | Medium | Схема БД |
| D3 | Нет FK на заказы (cart/session/event); CASCADE на платежи | `orders`, `payments` tables | Medium | Схема БД |
| D4 | Потенциальные Deadlocks из-за разного порядка блокировок | `CartItemService` vs `Checkout` | Medium | Конкурентность |
| D6 | Молчаливый пропуск CHECK constraints и триггеров | `2026_09_20_001000`, `safeUnprepared` | Medium | Миграции |
| D7 | Отсутствие проверки валюты при addItem | `CartItemService` | Low-Med | Бизнес-логика |
| D9 | Middleware идемпотентности не назначен на роуты | `routes/*.php` | Low | Безопасность/API |
| D10 | Revenue report использует float | `OrderService` | Low | Отчетность |

### Рекомендуемые действия (Приоритеты)

1.  **P0 (Критично):**
    *   Исправить обработку вебхуков (D5): Вынести claim события из транзакции бизнес-логики. При ошибке холда — фиксировать ошибку, но помечать событие как обработанный (с последующим авто-refund).
    *   Исправить админский путь заказов (D1): Добавить `lockForUpdate` и материализацию `seat_holds` или запретить прямой decrement без холдов.

2.  **P1 (Высокий):**
    *   Добавить `UNIQUE(session_id, seat_id)` в таблицу `tickets` (D2).
    *   Восстановить FK на `orders` (cart, session, event) и изменить ON DELETE на RESTRICT для `payments/refunds` (D3).
    *   Внедрить CI-гейт для проверки успешного применения всех CHECK constraints и триггеров на целевых окружениях (D6).

3.  **P2 (Средний):**
    *   Унифицировать порядок блокировок ресурсов (по ID инвентаря) и добавить retry-декоратор для транзакций (D4).
    *   Назначить middleware `idempotent` на критические POST-маршруты (checkout, payment init) (D9).
    *   Добавить проверку совпадения валюты в `CartItemService` (D7).

4.  **P3 (Низкий/Архитектурный долг):**
    *   Устранить дублирование моделей (плоские vs модульные).
    *   Ликвидировать осиротевшую таблицу `event_dates`.
    *   Синхронизировать `migrations.sql` и код миграций (X1).
