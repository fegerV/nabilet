-- 022: розыгрыши «счастливый билет» (фаза 5 плана `docs/LOYALTY-PLAN.md`).
--
-- ЗАЧЕМ ЭТОТ ФАЙЛ
--   План фазы 5 открывается розыгрышем: приз за покупку, которую организатор
--   разыгрывает среди покупателей. Решения заказчика: участником становится
--   КАЖДЫЙ оплаченный заказ из окна розыгрыша (без галочки в чекауте), тираж
--   проводит организатор кнопкой в админке (без планировщика), призовых мест
--   несколько, у каждого свой приз. Ниже — ровно эта модель.
--
-- ЗАЧЕМ БИЛЕТ — ЭТО ЗАКАЗ, А НЕ ЧЕЛОВЕК
--   Покупатель по умолчанию — гость без аккаунта (`customer_email` NOT NULL,
--   аккаунт заводится на чекауте, см. фазу 4): «одна строка на человека»
--   потеряла бы всех, кто так и не завёл аккаунт. Заказ есть у обоих, и
--   заказ — та единица, за которую организатор готов отдать приз: в заказе
--   лежит адрес, по которому победителя находят, даже если `user_id` так и
--   не появился. Поэтому UNIQUE (raffle_id, order_id), а не (raffle_id,
--   user_id).
--
-- ПОЧЕМУ ОКНО — ДВЕ ДАТЫ, А НЕ СТАТУС ЗАКАЗА
--   Условие входа «заказ оплачен в период розыгрыша» проверяется в момент
--   ОПЛАТЫ (`orders.paid_at`), а не в момент тиража: иначе розыгрыш пришлось
--   бы пересчитывать на каждое изменение статуса заказа. Обе даты nullable —
--   «с начала времён» и «до отмены» это отсутствие границы, а не 1970-01-01:
--   заказ, оплаченный до создания розыгрыша, легально участвует в розыгрыше
--   «за всё время», и NULL выражает это без магических значений.
--
-- ПОЧЕМУ ПРИЗЫ — ОТДЕЛЬНАЯ ТАБЛИЦА С МЕСТОМ
--   Призовых мест несколько, и каждое со своим призом: place = 1, 2, 3...
--   UNIQUE (raffle_id, place) — словарь мест закрыт на уровне розыгрыша, и
--   «два первых места» невозможны. Место, а не порядковый номер строки:
--   порядок сортировки меняется от запроса к запросу, а место — свойство
--   приза.
--
-- ПОЧЕМУ ТИРАЖ РУЧНОЙ, А НЕ ПО ПЛАНИРОВЩИКУ
--   Статус `drawn` + `drawn_at` фиксируют факт: `draft` — розыгрыш ещё
--   готовится, `active` — принимает участников, `drawn` — разыгран;
--   `cancelled` — организатор закрыл розыгрыш без тиража. Решение
--   о тираже принимает человек, и в схеме для этого достаточно одной колонки
--   со словарём: планировщик, когда появится, будет только менять `status`,
--   а не жить в отдельном флаге «запущено по расписанию».
--
-- ПОЧЕМУ ПОБЕДИТЕЛИ — ОТДЕЛЬНАЯ ТАБЛИЦА, А НЕ СТАТУС НА ЗАПИСИ
--   Запись (`raffle_entries`) — факт участия, её создаёт оплата. Победитель
--   (`raffle_winners`) — результат тиража, его создаёт человек кнопкой.
--   Разные авторы, разное время, разные жизненные циклы: отметка «этот
--   участник победил» на записи означала бы, что оплата (или её повтор)
--   когда-нибудь пишет в колонку победителя. Плюс у одного участника может
--   быть несколько призовых мест в разных розыгрышах, и «победитель» —
--   отношение, а не атрибут.
--
-- ИДЕМПОТЕНТНОСТЬ В СХЕМЕ
--   Три уникальных ключа держат инварианты на уровне базы, а не приложения:
--   `uq_raffle_entries_order` — один заказ даёт один билет в одном розыгрыше,
--   сколько бы раз ни сработал обсервер оплаты или повтор джобы;
--   `uq_raffle_winners_prize` — на призовое место ровно один победитель;
--   `uq_raffle_winners_entry` — один билет не забирает двух мест в одном
--   розыгрыше (тираж идёт без возврата, см. `Domain\RaffleDraw`).
--
-- ЧЕГО В СХЕМЕ НЕТ НАМЕРЕННО
--   Выплаты: приз описывают словами (`title`, `description`), его вручает
--   организатор — это не `bonus_transactions`. Бонус за участие тоже не
--   начисляется: участие — не повод для движения по счёту, иначе «крутить
--   барабан» дешевле, чем покупать.
--   `customer_email` на записи: адрес живёт в заказе (`orders.customer_email`),
--   и копия в `raffle_entries` расходилась бы с ним при правке заказа.
--   Отзыва entry при возврате: возврат НЕ удаляет билет — тот же принцип,
--   что у кэшбэка (фаза 2b) и реферальной награды (фаза 4): факт участия
--   уже зафиксирован, а удаление строк после тиража сделало бы результаты
--   невоспроизводимыми. Ограничение записано в `docs/LOYALTY-PLAN.md`.
--
-- ЗЕРКАЛО: database/migrations/2026_10_14_040000_create_raffles.php.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------- 1. розыгрыши
-- Строка на розыгрыш: название для покупателя, окно входа и жизненный цикл.
-- `draft` — розыгрыш готовится (призы можно править, участники не
-- набираются); `active` — оплаченные заказы из окна становятся билетами;
-- `drawn` — тираж проведён, `drawn_at` фиксирует когда; `cancelled` —
-- розыгрыш закрыт без тиража. Словарь закрыт CHECK ниже.
CREATE TABLE IF NOT EXISTS raffles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  title VARCHAR(255) NOT NULL,
  -- Описание для страницы результатов: что за приз и по каким правилам
  -- разыгрывается. NULL — организатор ничего не написал, и страница
  -- показывает только факты.
  description TEXT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'draft',
  -- Начало окна: заказы, оплаченные с этого момента, становятся билетами.
  -- NULL — окно открыто с начала времён.
  starts_at DATETIME(6) NULL,
  -- Конец окна: NULL — окно закрывается только тиражем. Концы окна
  -- проверяются в момент оплаты (`Order::paid_at`), а не в момент тиража:
  -- см. заголовок.
  ends_at DATETIME(6) NULL,
  -- Момент тиража. NOT NULL-колонка не нужна: статус `drawn` сам говорит,
  -- что тираж был, а дата — когда именно.
  drawn_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_raffles_public_id (public_id),
  -- «Розыгрыши, принимающие участников»: читается на КАЖДОЙ оплате заказа
  -- (обсервер `OrderRaffleObserver`), поэтому условие отбора — по статусу.
  KEY idx_raffles_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- 2. призы
-- Строка на призовое место одного розыгрыша. `place` — 1, 2, 3...: место
-- в схеме, а не в сортировке (см. заголовок). Каскад по розыгрышу: призы
-- без розыгрыша не существуют.
CREATE TABLE IF NOT EXISTS raffle_prizes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  raffle_id BIGINT UNSIGNED NOT NULL,
  place SMALLINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  -- Условия получения: «билет на любое мероприятие сезона», «мерч набором»
  -- и т.п. NULL — название приза говорит само за себя.
  description TEXT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_raffle_prizes_public_id (public_id),
  -- Одно призовое место — один приз: два первых места сделали бы тираж
  -- неопределённым (какому из них достаётся победитель места 1).
  UNIQUE KEY uq_raffle_prizes_place (raffle_id, place),
  CONSTRAINT fk_raffle_prizes_raffle FOREIGN KEY (raffle_id) REFERENCES raffles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Место нумеруется с единицы: `place = 0` не имеет смысла ни в одном
-- розыгрыше, и SMALLINT UNSIGNED сам по себе нуль допускает.
ALTER TABLE raffle_prizes
  ADD CONSTRAINT ck_raffle_prizes_place CHECK (place >= 1);

-- ------------------------------------------------------------- 3. билеты
-- Строка на «оплаченный заказ в розыгрыше». Создаётся один раз на заказ
-- (`OrderRaffleObserver` → `EnterRafflesJob`), правится только при создании:
-- `updated_at` не нужен, поэтому здесь единственная временная колонка.
-- Жизненный цикл записи — это её отсутствие или наличие, и отдельного
-- статуса у неё нет намеренно: «аннулированный билет» (возврат заказа) не
-- отзывается, см. заголовок.
--
-- `user_id` — снимок покупателя на момент входа: «мои участия» в кабинете
-- ищут по нему. SET NULL, а не CASCADE: заказ остаётся билетом даже после
-- удаления аккаунта (факт участия не должен исчезать вместе с профилем).
CREATE TABLE IF NOT EXISTS raffle_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  raffle_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_raffle_entries_public_id (public_id),
  -- Инвариант идемпотентности: повтор джобы, повторная запись статуса
  -- `paid` и гонка двух параллельных оплат дают одну строку, а не три.
  UNIQUE KEY uq_raffle_entries_order (raffle_id, order_id),
  -- «Мои участия»: кабинет покупателя (`GET /me/raffles`).
  KEY idx_raffle_entries_user (user_id),
  CONSTRAINT fk_raffle_entries_raffle FOREIGN KEY (raffle_id) REFERENCES raffles(id) ON DELETE CASCADE,
  -- Заказ удалён — билета нет: участие производно от заказа, а не наоборот.
  CONSTRAINT fk_raffle_entries_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_raffle_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Статус розыгрыша — из закрытого словаря (см. заголовок).
ALTER TABLE raffles
  ADD CONSTRAINT ck_raffles_status CHECK (status IN ('draft','active','drawn','cancelled'));

-- ------------------------------------------------------------- 4. победители
-- Строка на «победитель призового места». Создаётся тиражом (одна транзакция
-- на все места) и после этого не правится: `updated_at` нет по той же
-- причине, что у `raffle_entries`. `user_id` — снимок на момент тиража
-- (аккаунт победителя к тому моменту мог исчезнуть), `notified_at` —
-- письмо победителю ушло (идемпотентность уведомления и признак «можно
-- отправить повторно» в админке).
CREATE TABLE IF NOT EXISTS raffle_winners (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  raffle_id BIGINT UNSIGNED NOT NULL,
  raffle_prize_id BIGINT UNSIGNED NOT NULL,
  entry_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  notified_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_raffle_winners_public_id (public_id),
  -- На место — один победитель: повторный тираж не должен назначать второго.
  UNIQUE KEY uq_raffle_winners_prize (raffle_id, raffle_prize_id),
  -- Один билет — одно место в розыгрыше: тираж идёт без возврата
  -- (`Domain\RaffleDraw`), и ключ держит это инвариантом схемы.
  UNIQUE KEY uq_raffle_winners_entry (raffle_id, entry_id),
  CONSTRAINT fk_raffle_winners_raffle FOREIGN KEY (raffle_id) REFERENCES raffles(id) ON DELETE CASCADE,
  CONSTRAINT fk_raffle_winners_prize FOREIGN KEY (raffle_prize_id) REFERENCES raffle_prizes(id) ON DELETE CASCADE,
  CONSTRAINT fk_raffle_winners_entry FOREIGN KEY (entry_id) REFERENCES raffle_entries(id) ON DELETE CASCADE,
  CONSTRAINT fk_raffle_winners_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
