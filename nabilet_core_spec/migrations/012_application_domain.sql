SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================ 012 — application domain extensions
--
-- WHY THIS FILE EXISTS
--   Everything below was already part of the product, but lived only in
--   `database/migrations/*.php` and was tolerated by the verifier through the
--   `knownExtensions` / `APP_ONLY_TABLES` ratchets. That made the spec bundle an
--   incomplete description of the schema: a reader of `migrations.sql` alone
--   could not tell that an event has dates, speakers, sponsors, FAQs, artists
--   and a schedule, that a cart carries a currency and a total, that a hall has
--   an address and photos, that a seat can override the row price, or that a
--   hall may hold at most one live schema version.
--
--   The spec is the source of truth, so these belong in it. The ratchets shrink
--   accordingly — see tools/verify-migrations.php.
--
-- WHAT IS DELIBERATELY *NOT* HERE
--   `personal_access_tokens` (Sanctum), `jobs` / `failed_jobs` (Laravel queue)
--   and `users.remember_token` (Laravel auth) stay app-only. They are framework
--   plumbing, not NABILET domain: folding them into the Core spec would promise
--   a stable contract for tables that Laravel owns and may migrate itself.
--
-- NAMING
--   Index and foreign-key names below are the ones the applied migrations
--   actually created, including Laravel's auto-generated
--   `<table>_<columns>_index` / `<table>_<column>_foreign` forms. The spec's own
--   uq_/idx_/fk_ convention is not retrofitted here on purpose: renaming an index
--   is a schema change, and this file only records what already exists.

-- ---------------------------------------------------------- 1. event scheduling
-- An event may run on several dates (a multi-day festival, a run of shows).
-- `event_dates` is the anchor the schedule items below hang off.
CREATE TABLE IF NOT EXISTS event_dates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  start_at DATETIME(6) NOT NULL,
  end_at DATETIME(6) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'scheduled',
  name VARCHAR(255) NULL,
  capacity INT UNSIGNED NULL,
  is_sold_out TINYINT(1) NOT NULL DEFAULT 0,
  sales_start_at DATETIME(6) NULL,
  sales_end_at DATETIME(6) NULL,
  notes TEXT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  deleted_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_dates_public_id (public_id),
  KEY idx_event_dates_event (event_id),
  KEY idx_event_dates_start (start_at),
  KEY idx_event_dates_status (status),
  CONSTRAINT event_dates_event_id_foreign FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 2. event content extras
-- Five editorial side-tables. They carry no money and no inventory: they are
-- presentation content for the event page, hence `timestamps()` (nullable
-- TIMESTAMP) rather than the domain's DATETIME(6) NOT NULL pair.
CREATE TABLE IF NOT EXISTS event_speakers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  position VARCHAR(255) NULL,
  bio TEXT NULL,
  avatar VARCHAR(255) NULL,
  social_links JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY event_speakers_event_id_sort_order_index (event_id, sort_order),
  KEY event_speakers_is_featured_index (is_featured),
  CONSTRAINT event_speakers_event_id_foreign FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_sponsors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  logo VARCHAR(255) NULL,
  tier VARCHAR(255) NOT NULL DEFAULT 'standard',
  website VARCHAR(255) NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY event_sponsors_event_id_tier_sort_order_index (event_id, tier, sort_order),
  KEY event_sponsors_is_active_index (is_active),
  CONSTRAINT event_sponsors_event_id_foreign FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_faqs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  question VARCHAR(255) NOT NULL,
  answer TEXT NOT NULL,
  category VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY event_faqs_event_id_category_sort_order_index (event_id, category, sort_order),
  KEY event_faqs_is_active_index (is_active),
  CONSTRAINT event_faqs_event_id_foreign FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_artists (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  genre VARCHAR(255) NULL,
  bio TEXT NULL,
  photo VARCHAR(255) NULL,
  social_links JSON NULL,
  stage VARCHAR(255) NULL,
  performance_at DATETIME NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_headliner TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY event_artists_event_id_sort_order_index (event_id, sort_order),
  KEY event_artists_is_headliner_index (is_headliner),
  CONSTRAINT event_artists_event_id_foreign FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `event_date_id` is SET NULL, not CASCADE: deleting one day of a festival must
-- not delete the shared programme.
CREATE TABLE IF NOT EXISTS event_schedule_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  event_date_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  start_time TIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 60,
  location VARCHAR(255) NULL,
  type VARCHAR(255) NOT NULL DEFAULT 'session',
  speaker_ids JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  -- No explicit index on event_date_id: MySQL creates one automatically for the
  -- foreign key below, named after the constraint. Declaring it here as well
  -- would claim an index the migration set never asks for.
  KEY event_schedule_items_event_id_event_date_id_start_time_index (event_id, event_date_id, start_time),
  KEY event_schedule_items_type_index (type),
  CONSTRAINT event_schedule_items_event_date_id_foreign FOREIGN KEY (event_date_id) REFERENCES event_dates(id) ON DELETE SET NULL,
  CONSTRAINT event_schedule_items_event_id_foreign FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 3. cart currency and total
-- The Cart model and CartService wrote both from the start; the baseline carts
-- table simply never declared them.
ALTER TABLE carts
  ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'RUB' AFTER status,
  ADD COLUMN total_amount BIGINT NOT NULL DEFAULT 0 AFTER currency;

-- ---------------------------------------------------------- 4. hall details and seat price
-- Hall details are edited separately from the schema version, and a per-seat
-- price overrides the row price (NULL means "inherit from the row").
ALTER TABLE halls
  ADD COLUMN city VARCHAR(150) NULL AFTER description,
  ADD COLUMN address VARCHAR(500) NULL AFTER city,
  ADD COLUMN exterior_photo_url VARCHAR(2048) NULL AFTER address,
  ADD COLUMN interior_photo_url VARCHAR(2048) NULL AFTER exterior_photo_url;

ALTER TABLE seats
  ADD COLUMN price_amount BIGINT NULL AFTER number;

-- ---------------------------------------------------------- 5. hall schema revision + one live version
-- `revision` is nullable with NO default on purpose: giving it a default would
-- rewrite the logical value of every existing row. Legacy rows read as revision 1.
ALTER TABLE hall_schema_versions
  ADD COLUMN revision BIGINT UNSIGNED NULL AFTER schema_json;

-- At most one `published` and one `draft` version per hall, enforced by the
-- database rather than only by the service. MySQL has no partial indexes, so the
-- invariant is expressed as a generated column that holds `hall_id` for the live
-- row and NULL otherwise: UNIQUE accepts any number of NULLs, but exactly one
-- non-NULL per hall.
ALTER TABLE hall_schema_versions
  ADD COLUMN published_hall_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'published' THEN hall_id END) VIRTUAL,
  ADD COLUMN draft_hall_id BIGINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'draft' THEN hall_id END) VIRTUAL,
  ADD UNIQUE KEY uq_schema_one_published_per_hall (published_hall_id),
  ADD UNIQUE KEY uq_schema_one_draft_per_hall (draft_hall_id);

-- ---------------------------------------------------------- 6. Yandex.Metrika settings
-- Key/value so an admin can override counter id and goals without a deploy.
-- Values are JSON-encoded strings; MetrikaSettings reads them over config().
CREATE TABLE IF NOT EXISTS metrika_settings (
  `key` VARCHAR(100) NOT NULL,
  `value` TEXT NOT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 7. storefront settings
-- One row per organization; the whole storefront config is a JSON tree, because
-- a new section type must not require a schema change. organization_id = NULL is
-- the global default config, served to the guest storefront before a white-label
-- subdomain is resolved — hence it must never be deleted.
-- No foreign keys on purpose: the global row has organization_id = NULL, and the
-- config must survive an organization being removed.
CREATE TABLE IF NOT EXISTS storefront_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NULL,
  config JSON NOT NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY storefront_settings_organization_unique (organization_id),
  KEY storefront_settings_organization_id_index (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 8. day-before reminders log
-- A reminder is not an order status transition, so OrderObserver cannot see it:
-- a periodic sweep by time sends it, and the sweep runs again on every cron tick.
-- `uq_order_reminders_order` IS the idempotency guard, and it lives in the schema
-- rather than in code because a SELECT-then-INSERT check loses to a second
-- process. `sent_at` stays NULL when SMTP fails — "queued, not confirmed".
-- `session_id` is denormalised on purpose: a snapshot of which date was announced.
CREATE TABLE IF NOT EXISTS order_reminders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL,
  sent_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_reminders_order (order_id),
  UNIQUE KEY uq_order_reminders_public_id (public_id),
  KEY idx_order_reminders_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
