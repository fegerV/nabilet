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
| Избранное/подписки | ✅ фаза 1: `018_loyalty_foundation.sql`, 6 маршрутов `/me/*`, письма подписчикам, витрина |
| Бонусы (счёт и журнал) | ✅ фаза 2a: схема `019_bonus_ledger.sql`, начисление (`BonusPolicy`/`BonusService`/`OrderBonusObserver`), API баланса и журнала (`GET /me/bonus`, `GET /me/bonus/transactions`), витрина (чип «✦ N» в шапке, плитка в кабинете, экран `/bonus` с историей); списание в чекауте — ✅ фаза 2b |
| Рефералка | ✅ фаза 4: `021_referrals.sql`, `#/r/{code}` → атрибуция в чекауте → награда обеим сторонам после первой оплаты друга, настройки в админке (`GET/PUT /admin/loyalty/settings`, группа `referral_*`) |

## Архитектурные факты, на которых держится план

- **Точка выдачи бонусов** — `app/Modules/Orders/Services/OrderService.php:174` `markPaid()`:
  единственное место, где заказ становится `status=paid` (зовётся из
  `PaymentService::handlePaymentSucceeded()` → `applyPayment()`). Начисление
  подписано НЕ на `markPaid()`, а на `Order::updated()` (`OrderBonusObserver`):
  `paid` приходит и из других мест, и вызов из каждого значил бы гарантированно
  забыть один. Обсервер — это второй наблюдатель на ту же модель
  (`OrderObserver` модуля Orders остаётся первым).
- **Точка списания/скидки** — `app/Modules/Cart/Services/CartCheckoutService.php:336-343`:
  единственное место финализации сумм заказа (`OrderTotals::of()`, CHECK
  `ck_orders_amounts`).
- **Точка событийных уведомлений** — `app/Modules/Orders/Observers/OrderObserver.php:32`
  (`updated()` с `wasChanged('status')`, dispatch afterCommit) — образец подписки на события.
- **Деньги** — `app/Core/Support/Money.php` (int minor units + currency, без float).
- **Идентификаторы** — ULID → `public_id CHAR(26)` уникально везде.
- **Новый модуль** обязан иметь `module.json` (иначе `verify-module-structure.php` краснеет);
  `Domain/` должен быть фреймворк-фри (`verify-purity.php`).
- **Новая таблица** — новый нумерованный сплит `nabilet_core_spec/migrations/0NN_*.sql`
  + вручную написанное Laravel-зеркало `database/migrations/<ts>_*.php` (как 018 и
  019), плюс тот же текст в консолидированный `migrations.sql` и его побайтовую
  копию в корне. `verify-migrations.php` сверяет зеркало со спекой; `gen-migrations.py`
  — не для инкрементальных сплитов, он перегенерирует базовые 001–010 из дампа
  `information_schema`. В `$knownExtensions['tables']` идут только framework-таблицы
  (Sanctum, очередь, HTTP-сессии), которых в спеке нет: таблица из спеки туда не
  попадает, иначе diff перестанет её видеть.

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
| Витрина: ❤️ на `EventCard`, «🔔 Следить», экраны кабинета | ✅ `2beb9eb4` — `lib/loyalty.ts` + `stores/loyalty.ts`, ♡ на карточке афиши, «♡»/«🔔 Следить» на странице мероприятия, маршруты `/favorites` и `/subscriptions`, раздел «Клуб зрителей» в кабинете |
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

Фаза разделена на два независимых коммита: **2a** — схема, начисление и баланс
(аддитивно, денег не двигает), **2b** — списание в чекауте (двигает
`total_amount`, требует полного контура оплаты в тестах).

**Статус фазы 2** (обновляется по мере выполнения):

| Пункт плана | Состояние |
|---|---|
| Спека `019_bonus_ledger.sql` + Laravel-зеркало | ✅ `6aa74257` — `bonus_accounts` (+`CHECK balance >= 0`), append-only `bonus_transactions`, `orders.bonus_amount`, переизданный `ck_orders_amounts`; обе сборки применились на MySQL 8.4 побайтово одинаково (78 таблиц / 825 колонок / 113 FK / 98 UNIQUE / 50 CHECK) |
| Domain: `BonusPolicy` (5%, cap 30%, 12 мес) + идемпотентное начисление | ✅ — `Domain/BonusPolicy` + `Domain/BonusReason` (словарь зеркалит CHECK), `BonusAccount`/`BonusTransaction`, `BonusService::accrueForOrder()` (единственный писатель баланса, идемпотентность по `dedupe_key = accrual:{order_id}`), `AccrueOrderBonusJob` (`afterCommit`, `tries=3`), `OrderBonusObserver` — второй наблюдатель на `Order`, чтобы модуль Orders не знал о бонусах. Юнит `BonusPolicyTest` (20 тестов) + `BonusAccrualTest` (22 теста: момент начисления, повтор, гость, нулевой заказ, от итога, а не от subtotal, инварианты БД) |
| API: баланс и история движений (`/me/*`) + обе копии OpenAPI | ✅ — `GET /api/v1/me/bonus` (`BonusBalance`: `balance` в минорных единицах + `bonuses` по курсу 100, ноль при отсутствии счёта) и `GET /api/v1/me/bonus/transactions` (append-only журнал, `data`-список + `meta`, `order_id` = `orders.public_id`). Обе копии OpenAPI (111 операций / 110 схем, `verify-spec-parity` PASS), `BonusApiTest` (10 тестов) |
| Списание в чекауте (`CartCheckoutService`, `OrderTotals::of()`) | ✅ фаза 2b — `OrderTotals::of()` получил 5-й параметр `?Money $bonus = null` (`total = subtotal − effectiveDiscount − bonus + fee`, скидка обрезается ДО бонуса); `BonusService::spendableFor()` (кап под `lockForUpdate`, 0 для гостя) и `spendForOrder()` (журнал `spend:{order_id}` + `decrement` в одной транзакции, `INSUFFICIENT_BONUS_BALANCE` при 0 affected); сверх капа → `BONUS_LIMIT_EXCEEDED`. Тесты: `BonusSpendTest` (16) |
| Витрина: баланс (шапка + кабинет) и история движений | ✅ — `lib/loyalty.ts` (`fetchBonusBalance`/`fetchBonusTransactions`, подписи словаря причин), баланс в `stores/loyalty.ts` (грузится тем же `load()`, что и списки — один источник правды для чипа в шапке, плитки кабинета и экрана), страница `/bonus` (баланс + журнал с догрузкой), чип «✦ N» в `StorefrontShell`, плитка «Бонусный счёт» в кабинете. Тесты: `bonus.test.ts` (12), `bonusPage.test.ts` (11) |
| Витрина: применение бонусов в чекауте | ✅ фаза 2b — `CheckoutPage` предлагает чекбокс «Списать N бонусов» (кап = 30% subtotal, зеркало `spendCapMinor` в `lib/loyalty.ts`), строку «Бонусами −X ₽» в итоге и шлёт `bonus_amount`; `bonusPage.test.ts` инвертирован (обещает списание в корзине). Тесты: `checkoutBonus.test.ts` (15) |
| Гейты фазы 2 | ✅ фаза 2 полностью закрыта (2a + 2b): lint (755/0), tests/run.php (742/1791), phpunit (754/2904), vitest (317/25 файлов), vite build, 14 верификаторов — все зелёные, CI 5/5 |

**Единица измерения — уточнение к таблице правил ниже.** `bonus_accounts.balance`,
`bonus_transactions.delta` и `orders.bonus_amount` хранятся в МИНОРНЫХ единицах
(копейках). Иначе `bonus_amount` нельзя поставить в один CHECK с
`subtotal_amount`/`total_amount`: колонка в рублях рядом с колонками в копейках
сделала бы проверку бессмысленной. Строка «1 бонус = 1 ₽» из таблицы правил —
курс ОТОБРАЖЕНИЯ (1 ₽ = 100 минорных единиц), и делит их представление
(`BonusPolicy`/витрина), а не база.

**Идемпотентность начисления — по `dedupe_key`, а не по `UNIQUE (order_id,
reason)`.** Начисление обязано пройти ровно один раз на заказ, но ключ
`(order_id, reason)` закрыл бы и частичный возврат — законную ВТОРУЮ компенсацию
по тому же заказу. Необязательная уникальная колонка `dedupe_key` даёт писателю
самому объявить строку идемпотентной (`accrual:{order_id}`), а строки без ключа
друг другу не мешают.

**Фаза 2b — решения по списанию в чекауте (реализовано).** Списание происходит в момент
чекаута, а не резервом до оплаты: баланс — единственный источник правды для чипа в
шапке и капа следующего заказа. `BonusService::spendableFor()` считает кап под
`lockForUpdate` как `min(баланс, 30% subtotal, остаток после скидки)` (0 для гостя /
нет счёта); `CartCheckoutService::convert()` отказывает `BONUS_LIMIT_EXCEEDED` при
превышении и списывает через `spendForOrder()` в той же транзакции (`decrement` с
условием `balance >= amount`, иначе `INSUFFICIENT_BONUS_BALANCE` и откат). Компенсация
при `cancelled`/`expired`/`refunded` возвращает списанный бонус (`RefundOrderBonusJob`
→ `refundForOrder()`, идемпотентно по `refund:{order_id}`); статусы `payment_failed` и
`partially_refunded` НАМЕРЕННО исключены из авто-компенсации. **Ограничение фазы 2b:**
возврат НЕ отзывает начисленный кэшбэк (возвращается только списанный бонус).

### Фаза 3 — «Уровни клуба» + ранний доступ

`loyalty_levels` (Start/Friend/VIP, пороги по числу оплаченных заказов) +
пересчёт на `markPaid()`. Ранний доступ: `sessions.sales_start_at` + уровень →
«клубная» очередь продажи (проверка в контуре первого обращения к брони).

### Фаза 4 — «Реферальная программа»

`referrals(inviter_user_id, invited_email, code, status, bonus_paid_at)`,
ссылка `/r/{code}` → атрибуция (cookie/localStorage) → бонус обеим сторонам
после первой оплаты друга (по образцу идемпотентного `PromoCodeService::redeem()`).

**Статус фазы 4: реализовано** (`af648b87`).

| Что | Решение |
|---|---|
| Схема | `021_referrals.sql` + Laravel-зеркало: `referral_codes` (одна строка на приглашающего, `UNIQUE (user_id)` / `UNIQUE (code)`, ленивая выдача) и `referrals` (`UNIQUE (inviter_user_id, invited_email)` — инвариант идемпотентности, `pending → registered → rewarded`, `bonus_paid_at`). Словарь причин журнала расширен `referral_inviter` / `referral_invited` (CHECK переиздан — новое условие надмножество старого). Счётчики: 81 таблица / 849 колонок / 117 FK / 105 UNIQUE / 51 CHECK |
| Код | `Domain\ReferralCode` (фреймворк-фри): 16 символов, алфавит 32 знака без визуально похожих пар (`0`/`O`, `1`/`I` исключены), приведение = trim + верхний регистр |
| Атрибуция | НА ЧЕКАУТЕ, а не по клику: приглашение рождается из заказа. Витрина (`lib/referral.ts`) держит код в localStorage, экран `#/r/{code}` объясняет награду, `?ref=` ловится на любом адресе, чекаут шлёт `referral_code` |
| Ссылка | `#/r/{code}` — ХЭШ-путь: SPA отдаётся только на `/` (`routes/web.php`), чистый `/r/{code}` ушёл бы в `Route::fallback()` → 404. Тот же инвариант, что у ссылок в письмах |
| Друг = адрес | Покупатель по умолчанию — гость, поэтому приглашённый определяется `customer_email`; `invited_user_id` — уточнение, когда аккаунт заведён чекаутом |
| Награда | После ПЕРВОЙ оплаты друга: `OrderReferralObserver` (4-й наблюдатель на `Order`, только `paid`) → `RewardReferralJob` (`afterCommit`, `tries=3`) → `ReferralService::rewardForOrder()` — журнал + статус в одной транзакции. Идемпотентность двойная: статус `rewarded` и `dedupe_key` (`referral_inviter:{id}` / `referral_invited:{id}`) |
| Настройки | `GET/PUT /api/v1/admin/loyalty/settings` (группа `referral_*`, частичное обновление не затирает ранний доступ): вкл/выкл, награда обеим сторонам в минорных единицах, дефолт ₽300 обеим, потолок `ReferralPolicy::MAX_BONUS`. Экран «Клуб зрителей» в админке |
| Молчаливые отказы | Неизвестный, битый, свой и уже использованный код НЕ ломают покупку (201 без приглашения), выключенная программа не начисляет и не помечает приглашение награждённым |
| Ограничение | Возврат НЕ отзывает награду — то же правило, что у кэшбэка (фаза 2b): бонус уже на счёте. Записано намеренно |
| Тесты | `ReferralCodeTest` (10 unit, фреймворк-фри), `ReferralTest` (16 feature: атрибуция, битые коды, самоприглашение, выключенная программа, награда обеим сторонам, повтор джобы, возврат), `referral.test.ts` (11 vitest для `lib/referral.ts`) |
| OpenAPI | `CreateOrderRequest.referral_code`, `GET /api/v1/me/referral` + `ReferralInfo`, словарь причин `BonusTransaction.reason` — обе копии, `verify-openapi`/`verify-spec-parity` PASS |

### Фаза 5 — «Розыгрыш/счастливый билет», рекомендации, Telegram

Отдельные решения: розыгрыши требуют организаторского UI и правил призов;
рекомендации — аналитики (`events.view` + жанры); Telegram — реальный бот
вместо заглушки.

## Статус реализации (фаза 5 — розыгрыши «счастливый билет»)

Сплит `022_raffles.sql` (+ зеркало миграции) добавляет таблицы `raffles`,
`raffle_prizes`, `raffle_entries`, `raffle_winners` (все четыре с `public_id`,
CHECK `ck_raffles_status`, UNIQUE на составные ключи участия и победы). Каждая
подсистема закрыта отдельным коммитом со всеми гейтами зелёными.

| Подсистема | Что сделано | Гейты |
|---|---|---|
| Домен | `Domain\RaffleDraw`, `Domain\RaffleWindow`; машина состояний `RaffleStateMachine` (`draft→active→drawn`, `cancelled`) | `verify-state-machines` PASS |
| Сервис | `RaffleService`: набор участников (обсервер оплаты + backfill активации), `draw()` с `lockForUpdate`, рассылка писем победителям через очередь | phpunit `RaffleTest` OK (11 тестов, 32 проверки) |
| Задания | `EnterRafflesJob`, `BackfillRaffleEntriesJob`, `NotifyRaffleWinnersJob`, `OrderRaffleObserver` (5-й наблюдатель `Order`, только `paid`) | — |
| API | `AdminRaffleController` (`index/show/store/update/activate/cancel/draw/notify`), `RaffleController` (`me/raffles`, публичные `raffles/{public_id}`) | `verify-openapi` / `verify-spec-parity` PASS (обе копии) |
| Уведомления | `RaffleNotificationCodes` (Winner) + шаблон в `NotificationTemplateSeeder` | phpunit `TransactionalMailTest` OK |
| Админка (SPA) | `AdminRafflesPage.vue` (список, тираж, статус отправки писем), пункт в `AdminShell`, роут | `vue-tsc`, `vite build` PASS |
| Витрина (SPA) | `MyRafflesPage.vue` («Мои розыгрыши»), `RaffleResultsPage.vue` (публичные результаты с маской победителя), блок в `ClubPage`, `lib/raffles.ts` | vitest 342 PASS (28 файлов) |
| OpenAPI | 10 путей + 16 схем в обеих копиях (`docs/openapi.yaml`, `nabilet_core_spec/openapi.yaml`) | `verify-contract-schema` / `verify-openapi` PASS |

Известный внесистемный дрейф (не по фазе 5): `tools/verify-live.php` против
демо-БД `nabilet_demo` показывает расхождение консолидации спеки и живой БД
(`web_sessions` и ещё ряд ограничений есть в БД, но не в `migrations.sql`).
Это предшествующий фазе 5 дрейф консолидации миграций; рафл-таблицы (022)
присутствуют и в спеске, и в БД, поэтому к фазе 5 отношения не имеют. CI этот
гейт не запускает (он применяет `migrations.sql` к чистой БД).

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
