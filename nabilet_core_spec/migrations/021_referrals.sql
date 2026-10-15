-- 021: реферальная программа «Клуба зрителей» (фаза 4 плана `docs/LOYALTY-PLAN.md`).
--
-- ЗАЧЕМ ЭТОТ ФАЙЛ
--   План фазы 4: «`referrals(inviter_user_id, invited_email, code, status,
--   bonus_paid_at)`, ссылка `/r/{code}` → атрибуция (cookie/localStorage) →
--   бонус обеим сторонам после первой оплаты друга (по образцу идемпотентного
--   `PromoCodeService::redeem()`)». Ниже — ровно эта модель, с теми же
--   инвариантами, что и у остальных таблиц клуба: `public_id`, минорные
--   единицы, идемпотентность в схеме, закрытые словари.
--
-- ПОЧЕМУ ДВЕ ТАБЛИЦЫ, А НЕ ОДНА
--   Код приглашения — свойство ПРИГЛАШАЮЩЕГО, а приглашение — строка на
--   каждого ДРУГА, и жизненные циклы у них разные. Ссылку нужно уметь выдать
--   и разрешить ДО того, как друг что-то купил — и вообще до того, как друг
--   появился в базе: `/r/{code}` должен находить пригласившего, когда никаких
--   `referrals` ещё нет. Поэтому код живёт в `referral_codes` (одна строка на
--   человека, `UNIQUE (user_id)` — код один), а факт приглашения — в
--   `referrals` (одна строка на пару «пригласивший + email друга»). Одна
--   таблица не выражает этого: строка «код без друга» и строка «друг с
--   кодом» — разные сущности, и склеивание их сделало бы `UNIQUE (code)`
--   одновременно и ключом разрешения ссылки, и ключом приглашения.
--
-- ПОЧЕМУ EMAIL — КЛЮЧ ДРУГА, А НЕ USER_ID
--   Покупатель по умолчанию — гость без аккаунта (`customer_email` NOT NULL,
--   аккаунт заводится на чекауте по адресу). Ссылку друг открывает ДО
--   регистрации, и в момент открытия `user_id` у него нет. Адрес — единственный
--   идентификатор, который известен и до, и после: по нему приглашение
--   находится на чекауте, а `invited_user_id` проставляется, когда аккаунт
--   появился. UNIQUE (inviter_user_id, invited_email) — инвариант «один
--   пригласивший приглашает один адрес один раз»: повторный клик по той же
--   ссылке не создаёт вторую строку, а находит первую.
--
-- СТАТУС — ЖИЗНЕННЫЙ ЦИКЛ ПРИГЛАШЕНИЯ, А НЕ ФЛАГИ
--   `pending` — приглашение заведено, аккаунта друга ещё нет;
--   `registered` — аккаунт есть (`invited_user_id`), первая покупка не
--   оплачена; `rewarded` — бонусы начислены обеим сторонам, `bonus_paid_at`
--   фиксирует когда. Отдельный флаг «бонус выплачен» вместо статуса означал
--   бы две колонки, которые могут расходиться (выплачено, но не
--   зарегистрирован). Словарь закрыт CHECK: строка со статусом вне словаря
--   нечитаема — по ней нельзя ни построить отчёт, ни объяснить пригласившему,
--   почему бонус не пришёл.
--
-- КОМПЕНСАЦИЯ ПРИ ВОЗВРАТЕ — НАМЕРЕННО НЕ ПРЕДУСМОТРЕНА
--   Возврат заказа НЕ отзывает реферальный бонус — то же ограничение, что
--   записано для кэшбэка в фазе 2b: бонус уже на счёте, и отзыв потребовал бы
--   решения «что делать, если друг уже потратил оба бонуса». Ограничение
--   зафиксировано в `docs/LOYALTY-PLAN.md`, а не спрятано в коде.
--
-- СЛОВАРЬ ПРИЧИН ЖУРНАЛА РАСШИРЯЕТСЯ
--   Награда — две строки `bonus_transactions` (`referral_inviter` пригласившему,
--   `referral_invited` другу). Причина движения — закрытый словарь
--   (`BonusReason`, зеркало `ck_bonus_transactions_reason`), поэтому CHECK
--   переиздаётся здесь: в MySQL 8 CHECK нельзя изменить, только снять и
--   создать заново. Снятие безопасно — у всех существующих строк причина уже
--   из старого словаря, а новое условие его надмножество, поэтому ни одна
--   строка не может быть забракована.
--
-- ЗЕРКАЛО: database/migrations/2026_10_15_000100_create_referrals.php.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------- 1. код приглашения
-- Одна строка на приглашающего: код — адресная ссылка, и две ссылки у одного
-- человека означали бы «а какую из них считать моей» в кабинете и «куда
-- начислить» в разрешении `/r/{code}`. `code` — короткая строка, а не ULID:
-- её диктуют голосом и переписывают вручную, и 26 символов для этого —
-- ровно тот шум, из-за которого ссылку не перешлют. Алфавит генерации
-- (`ReferralCode`) исключает визуально похожие символы (0/O, 1/I), поэтому
-- 16 символов переживают пересылку в мессенджер.
--
-- `public_id` — как у всех таблиц клуба: внутренний BIGINT не покидает API
-- (`DATABASE.md` §9).
CREATE TABLE IF NOT EXISTS referral_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(16) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_referral_codes_public_id (public_id),
  -- Код один на человека — свойство приглашающего, а не каждой отправленной
  -- ссылки: см. заголовок.
  UNIQUE KEY uq_referral_codes_user (user_id),
  -- Ссылка `/r/{code}` обязана разрешаться без догадок: два человека с одним
  -- кодом сделали бы атрибуцию неопределённой.
  UNIQUE KEY uq_referral_codes_code (code),
  CONSTRAINT fk_referral_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 2. приглашения
-- Одна строка на пару «пригласивший + email друга». Строка заводится на
-- чекауте друга (атрибуция доезжает до сервера в теле оформления, см.
-- `ReferralService::attachToOrder()`), а не по клику: клик без покупки не
-- создаёт ничего — иначе список «приглашённых» в кабинете врал бы, показывая
-- тех, кто так и не купил.
--
-- Нет ни `updated_at`-логики, ни soft-delete: строка правится ровно дважды
-- (проставлен `invited_user_id`, затем статус `rewarded`), и история этих
-- правок не нужна — `bonus_transactions` хранят денежную сторону.
CREATE TABLE IF NOT EXISTS referrals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  inviter_user_id BIGINT UNSIGNED NOT NULL,
  -- Адрес друга в нижнем регистре: приведение делает сервис, и отличие —
  -- это другой ключ в UNIQUE ниже.
  invited_email VARCHAR(255) NOT NULL,
  -- Аккаунт друга. NULL, пока аккаунта нет (гость ещё не оформлял заказ);
  -- SET NULL — удаление аккаунта друга не стирает факт приглашения.
  invited_user_id BIGINT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  bonus_paid_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_referrals_public_id (public_id),
  -- Инвариант идемпотентности: повторное оформление по той же ссылке находит
  -- эту строку, а не создаёт вторую. Проверка «есть ли строка?» отдельным
  -- SELECT ненадёжна (два чекаута внахлёст) — страхует именно уникальный ключ.
  UNIQUE KEY uq_referrals_inviter_email (inviter_user_id, invited_email),
  -- «Приглашения, где друг — этот аккаунт»: награда ищется по `invited_user_id`.
  KEY idx_referrals_invited_user (invited_user_id),
  CONSTRAINT fk_referrals_inviter FOREIGN KEY (inviter_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_referrals_invited_user FOREIGN KEY (invited_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Статус приглашения — из закрытого словаря (см. заголовок).
ALTER TABLE referrals
  ADD CONSTRAINT ck_referrals_status CHECK (status IN ('pending','registered','rewarded'));

-- ---------------------------------------------------------- 3. словарь причин журнала
-- Награда клубу пишется в `bonus_transactions` причинами `referral_inviter` и
-- `referral_invited`. CHECK в MySQL 8 переиздаётся, а не правится: расширение
-- словаря — та самая правка, от которой план предостерегает, и она делается
-- отдельным сплитом, а не дозаписью в `019` (той миграции на развёрнутых
-- базах уже нет — остались её последствия). Снятие безопасно: новое условие
-- — надмножество старого, все существующие строки его проходят.
ALTER TABLE bonus_transactions DROP CONSTRAINT ck_bonus_transactions_reason;

ALTER TABLE bonus_transactions
  ADD CONSTRAINT ck_bonus_transactions_reason CHECK (reason IN (
    'accrual','registration','first_purchase','spend','refund','expiry',
    'referral_inviter','referral_invited'));

SET FOREIGN_KEY_CHECKS = 1;
