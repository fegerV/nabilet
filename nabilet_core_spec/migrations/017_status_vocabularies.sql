-- 017: словари status-колонок, за которыми уже стоит согласие кода.
--
-- ЗАЧЕМ ЭТОТ ФАЙЛ
--   §3.11 перечисляет 17 status-колонок без CHECK. Разбирать их одним списком
--   нельзя: за одними стоит словарь, который код уже написал и уже проверяет
--   (доменный класс, константы сервиса, правило валидации), за другими — никакого
--   словаря вовсе, и придумывать его здесь значило бы выдумать соглашение.
--
--   Этот файл берёт только первое. Значения НЕ ПРИДУМАНЫ ни для одной колонки:
--   каждое либо написано в домене, либо объявлено правилом запроса, либо
--   записано константой — то есть до этой миграции оно уже было правилом
--   приложения, просто не правилом базы.
--
-- ЧТО ОСТАЁТСЯ СВОБОДНЫМ — И ПОЧЕМУ ЭТО ЗАПИСАНО, А НЕ ЗАБЫТО
--   `idempotency_keys.response_status` — HTTP-код ответа (100…599). Словарь
--   здесь задаёт IANA, не приложение.
--   `payment_transactions.status` — свободна, но НЕ потому, что она «словарь
--   провайдера»: так было записано раньше, и это неверно. Ни один писатель не
--   копирует `status` из payload — значения пишем мы (PaymentStateMachine), а
--   сырой ответ провайдера уходит в `payload_json`. CHECK здесь не ставится
--   потому, что колонка осознанно NULL-able (строка журнала может фиксировать
--   факт события, не утверждая состояние), а правило «какой статус возможен при
--   каком типе записи» — это правило приложения, владелец
--   `Nabilet\Modules\Payments\Domain\TransactionType`, а не плоский список
--   значений. `payment_transactions.type` — закрытый словарь из четырёх
--   значений по той же причине: владелец тот же класс, проверку делает
--   единственный писатель (`PaymentRepository::addTransaction`).
--   `pages.status`, `promo_codes.status`, `halls.status`, `venues.status`,
--   `event_categories.status`, `event_dates.status`, `offline_bundles.status` —
--   значения только СРАВНИВАЮТСЯ ('active', 'published', 'draft', 'scheduled'),
--   но ни один писатель их не проверяет и ни один класс словарём не называет.
--   CHECK здесь был бы выдуманным соглашением; решение — продуктовое,
--   зафиксировано в §3.11 и §3.25.
--   `ab_experiments.status` — словарь объявлен в докблоке модели
--   ('draft'|'running'|'completed'), но нет ни одного пути записи: модуль
--   аналитики A/B не поднят. Колонка заперта в этом файле по докблоку — это
--   единственное объявление, какое у неё есть.
--
-- ПОЧЕМУ НОВЫЙ ФАЙЛ, А НЕ ПРАВКА 001/005/006
--   Те сплиты уже прошли на развёрнутых базах: ограничение, дописанное туда,
--   существовало бы в пакете и больше нигде. Новый сплит плюс зеркало
--   `2026_10_12_000500_add_status_vocabularies.php` достают и существующие, и
--   свежие установки — по той же причине, по которой существуют 011, 013–016.

ALTER TABLE carts
  ADD CONSTRAINT ck_carts_status CHECK (status IN ('active','converted','abandoned'));

ALTER TABLE checkin_devices
  ADD CONSTRAINT ck_checkin_devices_status CHECK (status IN ('active','disabled','revoked'));

ALTER TABLE consents
  ADD CONSTRAINT ck_consents_status CHECK (status IN ('granted','withdrawn','expired'));

ALTER TABLE privacy_requests
  ADD CONSTRAINT ck_privacy_requests_status CHECK (status IN ('requested','in_progress','completed','rejected'));

ALTER TABLE notifications
  ADD CONSTRAINT ck_notifications_status CHECK (status IN ('queued','sent','failed'));

ALTER TABLE organizations
  ADD CONSTRAINT ck_organizations_status CHECK (status IN ('active','inactive','suspended'));

ALTER TABLE ab_experiments
  ADD CONSTRAINT ck_ab_experiments_status CHECK (status IN ('draft','running','completed'));

-- `orders.payment_status` — две колонки про деньги в одной строке (§3.11):
-- `status` уже обеспечен `ck_orders_status`, а `payment_status` жила своим
-- словарём. Закрывается тем, что код РЕАЛЬНО пишет: 'pending' при создании
-- (`OrderService::createOrder`, `CartCheckoutService`) и 'succeeded' на
-- подтверждении (`OrderService::markPaid`); оба зафиксированы тестами
-- (`OrderWritePathTest`, `PaymentWritePathTest`). Возврат двигает `status`
-- (`refunded`/`partially_refunded`), а не `payment_status`, — поэтому третьего
-- значения здесь нет не по недосмотру.
-- Если однажды понадобится различать оплату и возврат деньгами, это новый
-- сплит с новым согласованным значением, а не правка этого файла.
-- Нормализация легаси значений ДО построения CHECK: на развёрнутых базах
-- встречалось orders.payment_status = 'paid' (писали до того, как markPaid() стал
-- писать 'succeeded'); ALTER с CHECK на строках вне словаря падал 3819.
UPDATE orders SET payment_status = 'succeeded' WHERE payment_status NOT IN ('pending','succeeded') AND payment_status LIKE 'pai%';

ALTER TABLE orders
  ADD CONSTRAINT ck_orders_payment_status CHECK (payment_status IN ('pending','succeeded'));

SET FOREIGN_KEY_CHECKS = 1;
