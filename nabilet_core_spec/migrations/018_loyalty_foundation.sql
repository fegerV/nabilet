-- 018: фундамент «Клуба зрителей» — избранное и подписки на мероприятия.
--
-- ЗАЧЕМ ЭТОТ ФАЙЛ
--   Фаза 1 плана `docs/LOYALTY-PLAN.md`: механика возврата, в которой нет денег.
--   Покупатель помечает мероприятие («❤️ — Избранное») или подписывается на него
--   («🔔 Следить»), и сайт сам присылает письмо, когда у артиста появляется
--   новый сеанс или открывается продажа. Ни одна таблица этого файла не участвует
--   в расчёте сумм: favourites/follows не знают ни о корзине, ни о заказе, ни о
--   ценах, поэтому фаза выкатывается независимо от чекаута и не может сдвинуть
--   `ck_orders_amounts`.
--
-- ПОЧЕМУ ДВЕ ТАБЛИЦЫ, А НЕ ОДНА С ФЛАГОМ
--   `event_favorites` и `event_follows` выглядят одинаково и намеренно разделены.
--   Избранное — личный список «хочу посмотреть»: писем не порождает. Подписка —
--   запрос на уведомления: порождает. Слить их в одну таблицу с флагом значило бы
--   отвечать на вопрос «получает ли человек письма?» чтением флага, который
--   никто не проверяет, и любая будущая рассылка по «избранному» начала бы
--   присылать письма тем, кто их не просил. Разделение делает намерение видимым
--   в схеме, а не в приложении.
--
--   `follow_notifications` — журнал идемпотентности отправки. Сеансы у
--   мероприятия появляются регулярно, и одно и то же письмо не должно уйти
--   дважды: уникальный ключ (follow_id, session_id, template_code) превращает
--   «отправить повторно» в конфликт вставки, который обрабатывается, а не в
--   дубликат в чужом почтовом ящике. SELECT-then-INSERT здесь недостаточно —
--   между ним и вставкой влезает второй процесс (та же защита, что
--   `uq_order_reminders_order` у напоминаний, `012_application_domain.sql`).
--
-- ЗНАЧЕНИЯ НЕ ВЫДУМАНЫ
--   `follow_notifications.status` — словарь `queued|sent|failed` взят у
--   `notifications.status` (`ck_notifications_status`, сплит 017): колонка
--   состояния отправки не заслуживает нового словаря, а расхождение со
--   словарём соседней таблицы — расхождение, которое кто-то будет ловить
--   в проде.
--
-- ПОЧЕМУ НОВЫЙ ФАЙЛ, А НЕ ПРАВКА 002/004
--   Те сплиты уже прошли на развёрнутых базах: дописанная туда таблица
--   существовала бы в пакете и больше нигде. Новый сплит плюс зеркало
--   `2026_10_13_000100_create_loyalty_foundation.php` достают и существующие,
--   и свежие установки — по той же причине, по которой существуют 011, 013–017.
--
-- Зеркало: database/migrations/2026_10_13_000100_create_loyalty_foundation.php.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------- 1. избранное
-- Личный список «хочу посмотреть». Писем не порождает, состояний и каналов не
-- имеет — только факт отметки и момент, когда она сделана.
CREATE TABLE IF NOT EXISTS event_favorites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_favorites_public_id (public_id),
  -- Повторное «❤️» на том же мероприятии — не вторая запись, а тот же факт: без
  -- уникального ключа список распухает от повторных нажатий, а счётчик «в
  -- избранном у N человек» начинает врать.
  UNIQUE KEY uq_event_favorites_user_event (user_id, event_id),
  -- «Моё избранное» — всегда список одного пользователя по времени добавления.
  KEY idx_event_favorites_user_created (user_id, created_at),
  CONSTRAINT fk_event_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_favorites_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 2. подписки
-- Запрос на уведомления о мероприятии. Отличается от избранного только
-- назначением (см. заголовок файла), поэтому и структура та же.
CREATE TABLE IF NOT EXISTS event_follows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_follows_public_id (public_id),
  UNIQUE KEY uq_event_follows_user_event (user_id, event_id),
  -- «Кому писать о новом сеансе этого мероприятия» — главный запрос фазы.
  KEY idx_event_follows_event (event_id, created_at),
  CONSTRAINT fk_event_follows_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_follows_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 3. журнал отправок
-- Идемпотентность подписных писем. Строка создаётся ДО постановки письма в
-- очередь; `sent_at` остаётся NULL, пока транспорт не подтвердил отправку, —
-- «запланировано, но не подтверждено» видно в разборе и не мешает повторной
-- попытке.
CREATE TABLE IF NOT EXISTS follow_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  follow_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  -- Код шаблона из `notification_templates.code` (не FK: у одного кода есть
  -- строки на каждый канал и локаль, и шаблон переживает редактуру).
  template_code VARCHAR(100) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  -- Строка в `notifications`, которая уже несла письмо. SET NULL: удаление
  -- записи транспорта не отменяет факта отправки подписчику.
  notification_id BIGINT UNSIGNED NULL,
  sent_at DATETIME(6) NULL,
  error_message TEXT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_follow_notifications_public_id (public_id),
  -- Ключ идемпотентности: на подписку × сеанс × шаблон — одно письмо.
  UNIQUE KEY uq_follow_notifications_dedupe (follow_id, session_id, template_code),
  -- «Что уже ушло этому человеку» — разбор жалоб «письмо пришло дважды».
  KEY idx_follow_notifications_user_created (user_id, created_at),
  KEY idx_follow_notifications_status (status),
  CONSTRAINT fk_follow_notifications_follow FOREIGN KEY (follow_id) REFERENCES event_follows(id) ON DELETE CASCADE,
  CONSTRAINT fk_follow_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_follow_notifications_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_follow_notifications_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_follow_notifications_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE follow_notifications
  ADD CONSTRAINT ck_follow_notifications_status CHECK (status IN ('queued','sent','failed'));

SET FOREIGN_KEY_CHECKS = 1;
