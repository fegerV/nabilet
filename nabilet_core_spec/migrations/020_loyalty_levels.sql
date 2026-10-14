-- 020: уровни «Клуба зрителей» (фаза 3 плана `docs/LOYALTY-PLAN.md`).
--
-- ЗАЧЕМ ОТДЕЛЬНАЯ ТАБЛИЦА, А НЕ КОНСТАНТА В КОДЕ
--   Пороги уровней (Старт/Friend/VIP по числу оплаченных заказов) — продуктовое
--   решение, а не деталь реализации одного вызывающего. Если бы они жили в
--   `LoyaltyTierPolicy`, правка «VIP теперь за 8 заказов» требовала бы релиза
--   бэкенда; в таблице она — строчка в сиде. Поэтому уровни — конфиг, читаемый
--   сервисом, а не литерал в правиле (`tools/verify-purity.php` держит Domain
--   фреймворк-фри именно затем, чтобы правила не зависели от базы).
--
-- ПОЧЕМУ КОЛОНКА НА `users`, А НЕ ОТДЕЛЬНАЯ ТАБЛИЦА СТАТУСОВ
--   Текущий уровень — ровно одна строка на человека, и она нужна на каждом
--   рендере кабинета. Отдельная таблица означала бы JOIN ради одного поля;
--   `users.loyalty_level_id` лежит в той же строке, что и профиль, и читается
--   бесплатно. NULL — ещё не пересчитанный уровень; трактуется как Старт.
--
-- ПЕРЕСЧЁТ НА СМЕНУ СТАТУСА ЗАКАЗА
--   Заказ становится `paid` из нескольких мест (см. `OrderBonusObserver`), поэтому
--   пересчёт висит на обсервере `OrderLoyaltyObserver` в `LoyaltyServiceProvider`,
--   а не вызовом из одной точки. Оплата поднимает уровень, возврат/отмена —
--   опускают: счёт ведётся по `COUNT(orders WHERE status='paid')`, и уровень —
--   производное от него, а не второй источник правды.
--
-- ИДЕМПОТЕНТНОСТЬ ПЕРЕСЧЁТА
--   Пересчёт пишет `users.loyalty_level_id` только при смене значения, поэтому
--   повторный переход в тот же статус не трогает строку. Это тот же принцип, что
--   у `OrderBonusObserver`: статус мог записаться повторно, и лишняя работа
--   бесполезна, а не вредна — но писать в базу без нужды мы не обязаны.
--
-- ЗЕРКАЛО: database/migrations/2026_10_14_020000_create_loyalty_levels.php.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------- 1. уровни клуба
-- Конфиг уровней. `min_paid_orders` — порог в оплаченных заказах; `sort_order`
-- упорядочивает витрину. `slug` — стабильный идентификатор для API и фронта.
CREATE TABLE IF NOT EXISTS loyalty_levels (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  slug VARCHAR(32) NOT NULL,
  name VARCHAR(64) NOT NULL,
  -- Порог в ОПЛАЧЕННЫХ заказах. 0 — для любого нового аккаунта (Старт).
  min_paid_orders INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_loyalty_levels_public_id (public_id),
  -- `slug` — ключ выбора уровня и поле ответа API, дубликат недопустим.
  UNIQUE KEY uq_loyalty_levels_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 2. уровень покупателя
-- Одна колонка на `users`. NULL допустим: уровень ещё не пересчитан (или аккаунт
-- гостя без заказов) — оба случая трактуются как Старт. FK SET NULL: удаление
-- уровня из конфига не должно унести покупателей, уровень просто обнулится и
-- пересчитается в Старт при следующей оплате.
ALTER TABLE users
  ADD COLUMN loyalty_level_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_users_loyalty_level FOREIGN KEY (loyalty_level_id)
    REFERENCES loyalty_levels(id) ON DELETE SET NULL;

-- ---------------------------------------------------------- 3. сид уровней
-- Пороги фазы 3: Старт с нуля, Friend за 3 оплаченных заказа, VIP за 10.
-- `public_id` — фиксированные 26-символьные ULID-подобные строки: зеркало
-- (Laravel-миграция) сгенерирует свои через `Str::ulid()`, данные не сверяются,
-- важен `slug`, по которому сервис выбирает уровень.
INSERT INTO loyalty_levels (public_id, slug, name, min_paid_orders, sort_order, created_at, updated_at) VALUES
  ('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'start', 'Старт', 0, 1, NOW(6), NOW(6)),
  ('01ARZ3NDEKTSV4RRFFQ69G5FAW', 'friend', 'Друг', 3, 2, NOW(6), NOW(6)),
  ('01ARZ3NDEKTSV4RRFFQ69G5FAX', 'vip', 'VIP', 10, 3, NOW(6), NOW(6));

SET FOREIGN_KEY_CHECKS = 1;
