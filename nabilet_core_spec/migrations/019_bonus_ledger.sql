-- 019: бонусный счёт «Клуба зрителей» — баланс, журнал движений, списание в заказе.
--
-- ЗАЧЕМ ЭТОТ ФАЙЛ
--   Фаза 2 плана `docs/LOYALTY-PLAN.md`. Фаза 1 (сплит 018) дала механику возврата
--   без денег: избранное и подписки. Эта фаза — единственная, которая трогает
--   суммы заказа, поэтому она и разбита на два независимых куска: начисление
--   (аддитивно, денег не двигает) и списание (двигает `total_amount`). Схема ниже
--   обслуживает оба, но ни одна существующая таблица не переписана: `orders`
--   получает одну колонку, а её CHECK переиздаётся. Новый сплит, а не правка 004,
--   потому что 004 давно прошёл на развёрнутых базах — колонка, дописанная туда,
--   существовала бы в бандле и больше нигде.
--
-- ПОЧЕМУ БАЛАНС И ЖУРНАЛ РАЗДЕЛЕНЫ
--   `bonus_accounts.balance` — текущее состояние, ровно одна строка на человека.
--   `bonus_transactions` — append-only журнал: строки не правятся и не удаляются,
--   потому что «почему у меня 340 бонусов» — вопрос, на который должен отвечать
--   набор строк, а не догадка. Баланс пересчитывается из журнала; журнал из
--   баланса — нет.
--
--   Отсюда требование к знаку: `delta` знаковый (плюс — начислили, минус —
--   списали, вернули, списали по сроку), а `reason` называет ПРИЧИНУ, а не
--   направление. Слить их в одну колонку значило бы потерять одно из двух:
--   `reason` без знака не отличает начисление от отката, знак без `reason` не
--   отличает кэшбэк от подарка за регистрацию.
--
-- ЕДИНИЦА ИЗМЕРЕНИЯ
--   `balance` и `delta` — в МИНОРНЫХ единицах, как и все деньги в этой схеме
--   (`Money`: int minor units, без float). Это не деталь: `orders.bonus_amount`
--   стоит в одном CHECK с `subtotal_amount` и `total_amount`, и колонка в рублях
--   рядом с колонками в копейках сделала бы этот CHECK бессмысленным.
--   Курс отображения («1 бонус = 1 ₽», `docs/LOYALTY-PLAN.md`) — правило витрины,
--   а не схемы: 1 ₽ = 100 минорных единиц, и делит их представление, не база.
--
-- ИДЕМПОТЕНТНОСТЬ: `dedupe_key`, А НЕ `UNIQUE (order_id, reason)`
--   Начисление обязано пройти ровно один раз на заказ, и проверка не может быть
--   «SELECT, потом INSERT» — между ними влезает второй воркер (та же защита, что
--   `uq_follow_notifications_dedupe` в 018). Но `UNIQUE (order_id, reason)`
--   закрыл бы и то, что закрывать нельзя: частичный возврат — законная ВТОРАЯ
--   компенсация по тому же заказу, и такой ключ сделал бы её невозможной.
--   Поэтому ключ идемпотентности — отдельная НЕОБЯЗАТЕЛЬНАЯ колонка: писатель
--   сам объявляет строку идемпотентной (`accrual:{order_id}`), а строки без
--   ключа (частичные возвраты) друг другу не мешают — MySQL допускает сколько
--   угодно NULL в UNIQUE. Тот же приём уже есть в схеме: `refunds.provider_refund_id`
--   и `payments.provider_payment_id` — необязательные уникальные колонки.
--
-- СЛОВАРЬ `reason` — СЛОВАРЬ ФАЗЫ 2, А НЕ ТОГО, ЧТО ПИШЕТСЯ СЕГОДНЯ
--   `accrual` — кэшбэк 5% от оплаченного заказа; `registration` (+100) и
--   `first_purchase` (+300) — правила из таблицы бонусов плана; `spend` — списание
--   в чекауте; `refund` — компенсация при возврате или отмене; `expiry` — истечение
--   через 12 месяцев. Расширение словаря — это ровно та правка CHECK, от которой
--   план предостерегает, поэтому словарь объявлен целиком и сразу, а не по мере
--   появления писателей.
--
-- ЗНАЧЕНИЯ НЕ ВЫДУМАНЫ
--   `currency` и `DEFAULT 'RUB'` — как у `orders`, `payments`, `refunds`: колонка
--   валюты без словаря, но с тем же дефолтом, что у соседей по деньгам.
--
-- Зеркало: database/migrations/2026_10_14_000100_create_bonus_ledger.php.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------- 1. счёт
-- Текущий баланс: одна строка на человека. `UNIQUE (user_id)` — не оптимизация,
-- а инвариант: две строки на одного пользователя означали бы два баланса и
-- вопрос «какой из них настоящий», который решается не в пользу покупателя.
CREATE TABLE IF NOT EXISTS bonus_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  -- Минорные единицы. BIGINT, а не INT: баланс живёт годами и не должен
  -- упираться в потолок, который никто не считал.
  balance BIGINT NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bonus_accounts_public_id (public_id),
  UNIQUE KEY uq_bonus_accounts_user (user_id),
  CONSTRAINT fk_bonus_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 2. журнал
-- Append-only. Ни `updated_at`, ни `deleted_at`: строка журнала не меняется
-- никогда — компенсация это НОВАЯ строка с обратным знаком, а не правка старой.
CREATE TABLE IF NOT EXISTS bonus_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  -- Заказ, из-за которого появилось движение. NULL для подарков
  -- (`registration`, `first_purchase`) и истечения: они не привязаны к заказу.
  -- SET NULL — удаление заказа не должно стирать историю бонусов покупателя.
  order_id BIGINT UNSIGNED NULL,
  -- Знаковое число в минорных единицах: плюс — начислили, минус — списали.
  delta BIGINT NOT NULL,
  reason VARCHAR(32) NOT NULL,
  -- Ключ идемпотентности. NULL — движение повторяемо (частичные возвраты);
  -- `accrual:{order_id}` — ровно одно на заказ. Необязательная уникальная
  -- колонка, как `refunds.provider_refund_id`. 191, а не 255: значение
  -- синтезируется кодом (`причина:id`), вводить его некому, и одному UNIQUE
  -- незачем занимать 255 символов utf8mb4.
  dedupe_key VARCHAR(191) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bonus_transactions_public_id (public_id),
  UNIQUE KEY uq_bonus_transactions_dedupe (dedupe_key),
  -- «История движений» — всегда список одного человека по времени.
  KEY idx_bonus_transactions_user_created (user_id, created_at),
  -- «Что журнал знает про этот заказ» — разбор начисления и его компенсации.
  KEY idx_bonus_transactions_order (order_id),
  CONSTRAINT fk_bonus_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_bonus_transactions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 3. списание в заказе
-- Отдельная колонка, а не «магическая скидка»: покупатель должен видеть строку
-- «списано бонусами», а разбор заказа — отличать скидку промокода от бонусов.
-- `AFTER discount_amount` держит суммы рядом. `DEFAULT 0` делает колонку
-- безопасной для всех уже существующих заказов — они получают ровно ноль.
ALTER TABLE orders
  ADD COLUMN bonus_amount BIGINT NOT NULL DEFAULT 0 AFTER discount_amount;

-- `ck_orders_amounts` переиздаётся, а не дописывается: CHECK в MySQL 8 нельзя
-- изменить, только снять и создать заново. Снятие и повторная установка
-- безопасны для существующих строк — у всех уже `bonus_amount = 0` (DEFAULT
-- выше), а условие `>= 0` для нуля истинно, поэтому проверка не может
-- забраковать ни одну строку, которую пропускала прежняя.
ALTER TABLE orders DROP CONSTRAINT ck_orders_amounts;

ALTER TABLE orders
  ADD CONSTRAINT ck_orders_amounts CHECK (
    subtotal_amount >= 0 AND discount_amount >= 0 AND fee_amount >= 0
    AND bonus_amount >= 0 AND total_amount >= 0);

-- ---------------------------------------------------------- 4. инварианты счёта
-- Баланс не уходит в минус. Это не «проверка на всякий случай»: минус означает
-- списание бонусов, которых у покупателя не было, — то есть подарок за счёт
-- организатора, который никто не заметит до сверки.
ALTER TABLE bonus_accounts
  ADD CONSTRAINT ck_bonus_accounts_balance CHECK (balance >= 0);

-- Причина движения — из закрытого словаря. Строка с причиной вне словаря
-- нечитаема: по ней нельзя ни построить отчёт, ни объяснить покупателю,
-- откуда взялись бонусы.
ALTER TABLE bonus_transactions
  ADD CONSTRAINT ck_bonus_transactions_reason CHECK (reason IN (
    'accrual','registration','first_purchase','spend','refund','expiry'));

SET FOREIGN_KEY_CHECKS = 1;
