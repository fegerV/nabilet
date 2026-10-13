# План: «Клуб зрителей NABILET» (Loyalty / CRM)

> Основание: стратегическое видение заказчика (не «сайт скидок», а клуб, где
> бонусный счёт + история + избранное + подписки + ранний доступ + персональные
> предложения создают петлю «купил → получил → вернулся → купил»).
>
> Этот документ — инженерный план: фазы, точки интеграции в СУЩЕСТВУЮЩИЙ код,
> модель данных в конвенциях репозитория, гейты. Источник истины по реализации.

## Что уже есть (не переделывать)

| Часть видения | Состояние в коде |
|---|---|
| Личный кабинет | ✅ `262ac0f5`: 5 экранов SPA, `/me/*` (профиль/пароль/заказы/сессии) |
| Промокоды (в т.ч. с ограничениями) | ✅ `Pricing`: `promo_codes`/`promo_code_redemptions` (DDL `010_tz_gaps.sql:27-83`), атомарный `redeem()`, `POST /promo-codes/validate` |
| Владелец заказа | ✅ с `da236164` у каждого заказа проставляется `user_id` — без этого лояльность невозможна |
| Email-уведомления | ✅ `Notifications`: шаблоны в БД, `SendOrderNotificationJob`, `OrderObserver` |
| Telegram | ⚠️ модуль `app/Modules/Telegram` — ЗАГЛУШКА (`TelegramServiceProvider` без кода) |
| Избранное/подписки/бонусы/рефералка | ❌ таблиц нет — этот план |

## Архитектурные факты, на которых держится план

- **Точка выдачи бонусов** — `app/Modules/Orders/Services/OrderService.php:174` `markPaid()`:
  единственное место, где заказ становится `status=paid` (зовётся из
  `PaymentService::handlePaymentSucceeded()` → `applyPayment()`).
- **Точка списания/скидки** — `app/Modules/Cart/Services/CartCheckoutService.php:336-343`:
  единственное место финализации сумм заказа (`OrderTotals::of()`, CHECK
  `ck_orders_amounts`).
- **Точка событийных уведомлений** — `app/Modules/Orders/Observers/OrderObserver.php:32`
  (`updated()` с `wasChanged('status')`, dispatch afterCommit) — образец подписки на события.
- **Деньги** — `app/Core/Support/Money.php` (int minor units + currency, без float).
- **Идентификаторы** — ULID → `public_id CHAR(26)` уникально везде.
- **Новый модуль** обязан иметь `module.json` (иначе `verify-module-structure.php` краснеет);
  `Domain/` должен быть фреймворк-фри (`verify-purity.php`).
- **Новая таблица** — сплит `nabilet_core_spec/migrations/018_*.sql` + Laravel-зеркало
  (`tools/gen-migrations.py`) + допуск в `$knownExtensions['tables']`
  `tools/verify-migrations.php`.

## Фазы

### Фаза 1 — «Не пропусти»: избранное + подписки (без денег, аддитивно)

Самая безопасная первая фаза: не трогает деньги и чекаут, но даёт механику
возврата («❤️» → «осталось 20% билетов» → «новое мероприятие артиста») и
становится фундаментом под фазы 2–4.

- Модуль `app/Modules/Loyalty` (+ `module.json`: requires Orders/Users/Notifications;
  provides `favorite.added`, `follow.created`, `session.created`).
- Спек `018_loyalty_foundation.sql`:
  - `event_favorites(user_id, event_id, created_at)` — UNIQUE(user_id, event_id),
    FK `ON DELETE CASCADE`;
  - `event_follows(user_id, event_id, created_at)` — UNIQUE(user_id, event_id);
  - `follow_notifications` (идемпотентность: follow_id + session_id + код письма).
- API под `auth:api`: `GET/DELETE /me/favorites`, `POST/DELETE /me/events/{event}/favorite`,
  `POST/DELETE /me/events/{event}/follow`, `GET /me/follows`.
- Уведомление подписчикам: при появлении сессии / открытии продажи
  (`sessions.sales_start_at` ≤ now) — письмо по образцу `SendOrderNotificationJob`.
- Витрина: ❤️ на `EventCard`, экран «Избранное», «🔔 Следить».
- Гейты: unit-тесты на Domain/уведомления, feature-тесты на `/me/*` и идемпотентность,
  сплит + зеркало + knownExtensions, обе копии OpenAPI + `verify-spec-parity`.

**Статус фазы 1** (обновляется по мере выполнения):

| Пункт плана | Состояние |
|---|---|
| Модуль `app/Modules/Loyalty` (+ `module.json`) | ✅ `31531aa6` — модели, сервисы, контроллеры, ресурсы, 6 маршрутов |
| Спека `018_loyalty_foundation.sql` + Laravel-зеркало | ✅ `31531aa6` — обе сборки применились на MySQL 8.4, счётчики в CI и верификаторах |
| API под `auth:api` (6 путей `/me/*`) | ✅ `31531aa6` — обе копии OpenAPI, `verify-spec-parity` |
| Письма подписчикам (новый сеанс / открытие продаж) | ✅ `82f9bb61` — `SessionObserver` → `NotifyFollowersOfNewSessionJob`, шаблоны `follow.new_session` / `follow.sales_open`, журнал `follow_notifications` как защита от повтора |
| Витрина: ❤️ на `EventCard`, «🔔 Следить», экраны кабинета | ✅ SPA — `lib/loyalty.ts` + `stores/loyalty.ts`, ♡ на карточке афиши, «♡»/«🔔 Следить» на странице мероприятия, маршруты `/favorites` и `/subscriptions`, раздел «Клуб зрителей» в кабинете |
| Гейты | ✅ 706 phpunit (2696 утверждений), 279 vitest (22 файла), lint, tests/run.php, 13 верификаторов, live-база |

### Фаза 2 — «Бонусный счёт» (деньги)

Ядро концепции. Ключевое контрактное решение: добавить в `MoneyTotals` поле
`bonus_amount` (списано бонусами) — покупатель должен видеть это отдельной
строкой, а не «магической скидкой».

- `019_bonus_ledger.sql`: `bonus_accounts(user_id UNIQUE, balance BIGINT, currency)`
  с `CHECK (balance >= 0)`; `bonus_transactions` — append-only журнал
  (`user_id, order_id, delta BIGINT, reason, created_at`), словарь reason в CHECK.
- `orders.bonus_amount BIGINT NOT NULL DEFAULT 0` + правка CHECK `ck_orders_amounts`
  (новым сплитом, не редактированием старых) и `OrderTotals::of()`
  (`total = subtotal - effectiveDiscount - bonus + fee`).
- Начисление: `OrderService::markPaid()` → afterCommit → `BonusService::accrue()`
  (5% от `total_amount`, ledger-запись, идемпотентность по order_id).
- Списание: `CartCheckoutService::convert()` → оценка «сколько бонусов можно
  списать» (cap 30% от subtotal), резерв до оплаты, фиксация при `markPaid`.
  Возврат/отмена → компенсирующая транзакция (откат).
- Витрина/кабинет: баланс в шапке/кабинете, применение в чекауте, история движений.
- Гейты: unit на Domain (cap, округление, возврат), feature на чекаут с бонусами
  через полный контур оплаты, обе копии OpenAPI.

### Фаза 3 — «Уровни клуба» + ранний доступ

`loyalty_levels` (Start/Friend/VIP, пороги по числу оплаченных заказов) +
пересчёт на `markPaid()`. Ранний доступ: `sessions.sales_start_at` + уровень →
«клубная» очередь продажи (проверка в контуре первого обращения к брони).

### Фаза 4 — «Реферальная программа»

`referrals(inviter_user_id, invited_email, code, status, bonus_paid_at)`,
ссылка `/r/{code}` → атрибуция (cookie/localStorage) → бонус обеим сторонам
после первой оплаты друга (по образцу идемпотентного `PromoCodeService::redeem()`).

### Фаза 5 — «Розыгрыш/счастливый билет», рекомендации, Telegram

Отдельные решения: розыгрыши требуют организаторского UI и правил призов;
рекомендации — аналитики (`events.view` + жанры); Telegram — реальный бот
вместо заглушки.

## Правила бонусов (предлагаемые значения по умолчанию)

| Правило | Значение |
|---|---|
| Курс | 1 бонус = 1 ₽ (минорная единица = 1 копейка = 1 бонус) |
| Начисление | 5% от `orders.total_amount` (итого к оплате) |
| Регистрация / первая покупка | +100 / +300 бонусов (видение заказчика) |
| Лимит списания | ≤ 30% от subtotal заказа |
| Срок жизни | 12 месяцев с момента начисления |
| Возврат | компенсирующая транзакция, баланс не уходит в минус |

## Риски и порядок

1. **Деньги**: любая правка сумм заказа — через `OrderTotals` + CHECK + обе копии
   OpenAPI + полный контур оплаты в feature-тестах (баг «sold_out→sold» из `bfd12903`
   учит: то, что не покрыто контуром оплаты, утекает).
2. **Идемпотентность**: начисление ровно один раз на заказ (по `order_id` в журнале),
   как атомарный `redemptions_count+1` у промокодов.
3. **Порядок фаз**: 1 → 2 → 3 → 4 → 5. Каждая фаза отдельным коммитом со всеми
   гейтами, без PR.
