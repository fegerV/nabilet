-- NABILET Core v1.0
-- MySQL 8.4+
-- Generated as an implementation baseline. Review naming/engine settings before production.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS organizations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  description TEXT NULL,
  logo VARCHAR(2048) NULL,
  email VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  settings_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_organizations_public_id (public_id),
  UNIQUE KEY uq_organizations_slug (slug),
  KEY idx_organizations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  description VARCHAR(500) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(150) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  email VARCHAR(255) NULL,
  email_verified_at DATETIME(6) NULL,
  password VARCHAR(255) NULL,
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  phone VARCHAR(50) NULL,
  phone_verified_at DATETIME(6) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  locale VARCHAR(10) NOT NULL DEFAULT 'ru',
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  last_login_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  deleted_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_public_id (public_id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_phone (phone),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_organization (
  user_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (user_id, organization_id),
  KEY idx_uo_org_role (organization_id, role_id),
  CONSTRAINT fk_uo_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_uo_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_uo_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  session_token_hash CHAR(64) NOT NULL,
  device_name VARCHAR(255) NULL,
  user_agent VARCHAR(1024) NULL,
  ip_address VARBINARY(16) NULL,
  last_seen_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_sessions_token (session_token_hash),
  KEY idx_user_sessions_user (user_id),
  KEY idx_user_sessions_expires (expires_at),
  CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  identifier VARCHAR(255) NULL,
  success TINYINT(1) NOT NULL,
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(1024) NULL,
  failure_code VARCHAR(100) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_login_logs_user_time (user_id, created_at),
  KEY idx_login_logs_identifier_time (identifier, created_at),
  CONSTRAINT fk_login_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(150) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_categories_public_id (public_id),
  UNIQUE KEY uq_event_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  -- Оформление билета для этого мероприятия. NULL — стандартный вид билета.
  -- Макет принадлежит МЕРОПРИЯТИЮ, а не сеансу: у концерта с дневным и вечерним
  -- сеансами билет обязан выглядеть одинаково, иначе покупатель решает, что
  -- один из двух билетов поддельный. ON DELETE SET NULL, а не CASCADE: удаление
  -- макета не должно уносить с собой мероприятие вместе с заказами.
  ticket_template_id BIGINT UNSIGNED NULL,
  title VARCHAR(500) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  short_description TEXT NULL,
  description LONGTEXT NULL,
  poster VARCHAR(2048) NULL,
  cover VARCHAR(2048) NULL,
  age_limit VARCHAR(32) NULL,
  duration_minutes INT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  published_at DATETIME(6) NULL,
  seo_title VARCHAR(500) NULL,
  seo_description VARCHAR(1000) NULL,
  canonical_url VARCHAR(2048) NULL,
  robots VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  deleted_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_events_public_id (public_id),
  -- Slug глобально уникален, хотя исторически был уникален в паре с
  -- organization_id. Публичный адрес события — /event/{slug}/{publicId} —
  -- организации не содержит, поэтому «один slug на две организации» означал бы,
  -- что один и тот же URL открывает произвольное из двух событий, а canonical
  -- двух страниц неразличим. См. database/migrations/
  -- 2026_10_11_000100_make_event_slug_globally_unique.php (SEO-аудит P-03).
  UNIQUE KEY uq_events_slug (slug),
  KEY idx_events_org_status (organization_id, status),
  KEY idx_events_category (category_id),
  KEY idx_events_published (published_at),
  KEY idx_events_ticket_template (ticket_template_id),
  CONSTRAINT fk_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_events_category FOREIGN KEY (category_id) REFERENCES event_categories(id) ON DELETE SET NULL,
  -- Ссылка вперёд: ticket_templates объявлена ниже по файлу. Это безопасно,
  -- потому что файл открывается `SET FOREIGN_KEY_CHECKS = 0`.
  CONSTRAINT fk_events_ticket_template FOREIGN KEY (ticket_template_id) REFERENCES ticket_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_translations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  locale VARCHAR(10) NOT NULL,
  title VARCHAR(500) NULL,
  short_description TEXT NULL,
  description LONGTEXT NULL,
  seo_title VARCHAR(500) NULL,
  seo_description VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_translations (event_id, locale),
  CONSTRAINT fk_event_translations_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS venues (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  description TEXT NULL,
  country VARCHAR(100) NULL,
  region VARCHAR(150) NULL,
  city VARCHAR(150) NULL,
  address VARCHAR(500) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_venues_public_id (public_id),
  -- Slug площадки глобально уникален: публичный адрес `/venue/{slug}`
  -- организации не содержит. Код это уже предполагал (`VenueController`
  -- валидирует `unique:venues,slug` и генерирует адрес циклом по всей таблице),
  -- а схема — нет. См. database/migrations/
  -- 2026_10_11_000200_make_venue_slug_globally_unique.php.
  UNIQUE KEY uq_venues_slug (slug),
  KEY idx_venues_org_city (organization_id, city),
  CONSTRAINT fk_venues_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS halls (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  venue_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  capacity INT UNSIGNED NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_halls_public_id (public_id),
  UNIQUE KEY uq_halls_venue_name (venue_id, name),
  CONSTRAINT fk_halls_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hall_schema_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  hall_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  background_url VARCHAR(2048) NULL,
  schema_json JSON NOT NULL,
  published_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schema_public_id (public_id),
  UNIQUE KEY uq_schema_hall_version (hall_id, version),
  KEY idx_schema_hall_status (hall_id, status),
  CONSTRAINT fk_schema_hall FOREIGN KEY (hall_id) REFERENCES halls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sectors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  schema_version_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  code VARCHAR(100) NOT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'seated',
  x DECIMAL(12,3) NOT NULL DEFAULT 0,
  y DECIMAL(12,3) NOT NULL DEFAULT 0,
  width DECIMAL(12,3) NULL,
  height DECIMAL(12,3) NULL,
  color VARCHAR(32) NULL,
  capacity INT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sectors_public_id (public_id),
  UNIQUE KEY uq_sector_schema_code (schema_version_id, code),
  CONSTRAINT fk_sectors_schema FOREIGN KEY (schema_version_id) REFERENCES hall_schema_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hall_rows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  sector_id BIGINT UNSIGNED NOT NULL,
  number VARCHAR(50) NOT NULL,
  name VARCHAR(100) NULL,
  price_amount BIGINT NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  x DECIMAL(12,3) NULL,
  y DECIMAL(12,3) NULL,
  rotation DECIMAL(8,3) NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hall_rows_public_id (public_id),
  UNIQUE KEY uq_hall_rows_sector_number (sector_id, number),
  CONSTRAINT fk_hall_rows_sector FOREIGN KEY (sector_id) REFERENCES sectors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seats (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  row_id BIGINT UNSIGNED NOT NULL,
  number VARCHAR(50) NOT NULL,
  label VARCHAR(100) NULL,
  x DECIMAL(12,3) NOT NULL DEFAULT 0,
  y DECIMAL(12,3) NOT NULL DEFAULT 0,
  width DECIMAL(12,3) NULL,
  height DECIMAL(12,3) NULL,
  rotation DECIMAL(8,3) NOT NULL DEFAULT 0,
  type VARCHAR(32) NOT NULL DEFAULT 'standard',
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seats_public_id (public_id),
  UNIQUE KEY uq_seats_row_number (row_id, number),
  CONSTRAINT fk_seats_row FOREIGN KEY (row_id) REFERENCES hall_rows(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hall_tables (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  sector_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  x DECIMAL(12,3) NOT NULL DEFAULT 0,
  y DECIMAL(12,3) NOT NULL DEFAULT 0,
  width DECIMAL(12,3) NOT NULL,
  height DECIMAL(12,3) NOT NULL,
  rotation DECIMAL(8,3) NOT NULL DEFAULT 0,
  capacity INT UNSIGNED NULL,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hall_tables_public_id (public_id),
  CONSTRAINT fk_hall_tables_sector FOREIGN KEY (sector_id) REFERENCES sectors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS standing_zones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  sector_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  capacity INT UNSIGNED NOT NULL,
  price_amount BIGINT NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_standing_public_id (public_id),
  UNIQUE KEY uq_standing_sector_name (sector_id, name),
  CONSTRAINT fk_standing_sector FOREIGN KEY (sector_id) REFERENCES sectors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  venue_id BIGINT UNSIGNED NOT NULL,
  hall_id BIGINT UNSIGNED NOT NULL,
  schema_version_id BIGINT UNSIGNED NOT NULL,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NULL,
  sales_start_at DATETIME(6) NULL,
  sales_end_at DATETIME(6) NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_public_id (public_id),
  KEY idx_sessions_event_start (event_id, starts_at),
  KEY idx_sessions_venue_start (venue_id, starts_at),
  KEY idx_sessions_status_start (status, starts_at),
  CONSTRAINT fk_sessions_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sessions_hall FOREIGN KEY (hall_id) REFERENCES halls(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sessions_schema FOREIGN KEY (schema_version_id) REFERENCES hall_schema_versions(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL,
  seat_id BIGINT UNSIGNED NULL,
  standing_zone_id BIGINT UNSIGNED NULL,
  price_amount BIGINT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  capacity INT UNSIGNED NOT NULL DEFAULT 1,
  available_quantity INT NOT NULL DEFAULT 1,
  status VARCHAR(32) NOT NULL DEFAULT 'available',
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_public_id (public_id),
  UNIQUE KEY uq_inventory_session_seat (session_id, seat_id),
  UNIQUE KEY uq_inventory_session_standing (session_id, standing_zone_id),
  KEY idx_inventory_session_status (session_id, status),
  CONSTRAINT fk_inventory_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_seat FOREIGN KEY (seat_id) REFERENCES seats(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_standing FOREIGN KEY (standing_zone_id) REFERENCES standing_zones(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS carts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  expires_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_carts_public_id (public_id),
  KEY idx_carts_user_status (user_id, status),
  KEY idx_carts_session_status (session_id, status),
  CONSTRAINT fk_carts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_carts_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cart_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cart_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price BIGINT NOT NULL,
  total_price BIGINT NOT NULL,
  seat_snapshot_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cart_inventory (cart_id, inventory_item_id),
  CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
  CONSTRAINT fk_cart_items_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seat_holds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  cart_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  released_at DATETIME(6) NULL,
  converted_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hold_public_id (public_id),
  KEY idx_holds_inventory_expire (inventory_item_id, expires_at),
  KEY idx_holds_cart (cart_id),
  KEY idx_holds_expire (expires_at),
  CONSTRAINT fk_holds_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_holds_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_holds_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  order_number VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  subtotal_amount BIGINT NOT NULL,
  discount_amount BIGINT NOT NULL DEFAULT 0,
  fee_amount BIGINT NOT NULL DEFAULT 0,
  total_amount BIGINT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  payment_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  customer_email VARCHAR(255) NOT NULL,
  customer_phone VARCHAR(50) NULL,
  created_at DATETIME(6) NOT NULL,
  paid_at DATETIME(6) NULL,
  cancelled_at DATETIME(6) NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_public_id (public_id),
  UNIQUE KEY uq_orders_number (order_number),
  KEY idx_orders_user_time (user_id, created_at),
  KEY idx_orders_org_status (organization_id, status),
  KEY idx_orders_payment_status (payment_status),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_orders_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price BIGINT NOT NULL,
  discount_amount BIGINT NOT NULL DEFAULT 0,
  fee_amount BIGINT NOT NULL DEFAULT 0,
  total_amount BIGINT NOT NULL,
  event_title_snapshot VARCHAR(500) NOT NULL,
  session_title_snapshot VARCHAR(500) NULL,
  venue_title_snapshot VARCHAR(500) NULL,
  seat_snapshot_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_order_items_order (order_id),
  KEY idx_order_items_inventory (inventory_item_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_order_items_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(64) NOT NULL,
  provider_payment_id VARCHAR(255) NULL,
  amount BIGINT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  payment_url VARCHAR(2048) NULL,
  idempotency_key VARCHAR(255) NOT NULL,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  paid_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_public_id (public_id),
  UNIQUE KEY uq_payments_idempotency (provider, idempotency_key),
  UNIQUE KEY uq_payments_provider_id (provider, provider_payment_id),
  KEY idx_payments_order (order_id),
  KEY idx_payments_status (status),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id BIGINT UNSIGNED NOT NULL,
  provider_event_id VARCHAR(255) NULL,
  type VARCHAR(64) NOT NULL,
  amount BIGINT NULL,
  currency CHAR(3) NULL,
  status VARCHAR(32) NULL,
  payload_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_transactions_event (payment_id, provider_event_id),
  KEY idx_payment_transactions_payment (payment_id),
  CONSTRAINT fk_payment_transactions_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  amount BIGINT NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  reason VARCHAR(500) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'requested',
  provider_refund_id VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL,
  completed_at DATETIME(6) NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_refunds_public_id (public_id),
  UNIQUE KEY uq_refunds_provider_id (provider_refund_id),
  KEY idx_refunds_order (order_id),
  KEY idx_refunds_payment (payment_id),
  CONSTRAINT fk_refunds_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  format VARCHAR(32) NOT NULL DEFAULT 'mobile',
  width INT UNSIGNED NOT NULL,
  height INT UNSIGNED NOT NULL,
  template_json JSON NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_templates_public_id (public_id),
  KEY idx_ticket_templates_org (organization_id),
  CONSTRAINT fk_ticket_templates_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  ticket_number VARCHAR(100) NOT NULL,
  ticket_index INT UNSIGNED NOT NULL DEFAULT 1,
  order_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  inventory_item_id BIGINT UNSIGNED NOT NULL,
  seat_id BIGINT UNSIGNED NULL,
  standing_zone_id BIGINT UNSIGNED NULL,
  holder_name VARCHAR(255) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'issued',
  qr_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  qr_token_hash CHAR(64) NOT NULL,
  issued_at DATETIME(6) NOT NULL,
  used_at DATETIME(6) NULL,
  cancelled_at DATETIME(6) NULL,
  refunded_at DATETIME(6) NULL,
  expired_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tickets_public_id (public_id),
  UNIQUE KEY uq_tickets_number (ticket_number),
  UNIQUE KEY uq_tickets_qr_hash (qr_token_hash),
  UNIQUE KEY uq_tickets_order_item_index (order_item_id, ticket_index),
  KEY idx_tickets_session_status (session_id, status),
  KEY idx_tickets_event_status (event_id, status),
  CONSTRAINT fk_tickets_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tickets_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tickets_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tickets_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tickets_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tickets_seat FOREIGN KEY (seat_id) REFERENCES seats(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tickets_standing FOREIGN KEY (standing_zone_id) REFERENCES standing_zones(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkin_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  device_token_hash CHAR(64) NOT NULL,
  platform VARCHAR(32) NOT NULL DEFAULT 'android',
  app_version VARCHAR(50) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  last_seen_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_checkin_devices_public_id (public_id),
  UNIQUE KEY uq_checkin_devices_token (device_token_hash),
  CONSTRAINT fk_checkin_devices_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_scans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  ticket_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  device_id BIGINT UNSIGNED NULL,
  client_scan_id CHAR(36) NULL,
  mode VARCHAR(32) NOT NULL,
  result VARCHAR(64) NOT NULL,
  scanned_at DATETIME(6) NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_scans_public_id (public_id),
  UNIQUE KEY uq_ticket_scans_client (device_id, client_scan_id),
  KEY idx_ticket_scans_ticket_time (ticket_id, scanned_at),
  KEY idx_ticket_scans_session_time (session_id, scanned_at),
  CONSTRAINT fk_ticket_scans_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_scans_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_scans_device FOREIGN KEY (device_id) REFERENCES checkin_devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  code VARCHAR(100) NOT NULL,
  channel VARCHAR(32) NOT NULL,
  locale VARCHAR(10) NOT NULL DEFAULT 'ru',
  subject VARCHAR(500) NULL,
  body_text LONGTEXT NULL,
  body_html LONGTEXT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_templates_public_id (public_id),
  UNIQUE KEY uq_notification_template_code_channel_locale (code, channel, locale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  channel VARCHAR(32) NOT NULL,
  type VARCHAR(100) NOT NULL,
  recipient VARCHAR(500) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  provider_message_id VARCHAR(255) NULL,
  payload_json JSON NULL,
  sent_at DATETIME(6) NULL,
  error_message TEXT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notifications_public_id (public_id),
  KEY idx_notifications_user_time (user_id, created_at),
  KEY idx_notifications_status (status),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NULL,
  title VARCHAR(500) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  content LONGTEXT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  published_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pages_public_id (public_id),
  UNIQUE KEY uq_pages_org_slug (organization_id, slug),
  CONSTRAINT fk_pages_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_meta (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(100) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  locale VARCHAR(10) NOT NULL DEFAULT 'ru',
  title VARCHAR(500) NULL,
  description VARCHAR(1000) NULL,
  canonical_url VARCHAR(2048) NULL,
  robots VARCHAR(255) NULL,
  og_title VARCHAR(500) NULL,
  og_description VARCHAR(1000) NULL,
  og_image VARCHAR(2048) NULL,
  schema_json JSON NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seo_entity_locale (entity_type, entity_id, locale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redirects (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(2048) NOT NULL,
  destination VARCHAR(2048) NOT NULL,
  status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_redirect_source (source(512)),
  KEY idx_redirect_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  anonymous_id CHAR(36) NULL,
  consent_type VARCHAR(64) NOT NULL,
  status VARCHAR(32) NOT NULL,
  policy_version VARCHAR(50) NULL,
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(1024) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_consents_user_type (user_id, consent_type),
  KEY idx_consents_anonymous_type (anonymous_id, consent_type),
  CONSTRAINT fk_consents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS privacy_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  type VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'requested',
  payload_json JSON NULL,
  created_at DATETIME(6) NOT NULL,
  completed_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_privacy_requests_public_id (public_id),
  KEY idx_privacy_requests_user (user_id),
  CONSTRAINT fk_privacy_requests_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS analytics_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  anonymous_id CHAR(36) NULL,
  session_id BIGINT UNSIGNED NULL,
  event_id BIGINT UNSIGNED NULL,
  event_name VARCHAR(100) NOT NULL,
  page_url VARCHAR(2048) NULL,
  referrer VARCHAR(2048) NULL,
  utm_source VARCHAR(255) NULL,
  utm_medium VARCHAR(255) NULL,
  utm_campaign VARCHAR(255) NULL,
  device VARCHAR(64) NULL,
  browser VARCHAR(128) NULL,
  country VARCHAR(100) NULL,
  properties_json JSON NULL,
  occurred_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_analytics_name_time (event_name, occurred_at),
  KEY idx_analytics_event_time (event_id, occurred_at),
  KEY idx_analytics_user_time (user_id, occurred_at),
  CONSTRAINT fk_analytics_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_analytics_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_analytics_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ab_experiments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  starts_at DATETIME(6) NULL,
  ends_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ab_experiments_public_id (public_id),
  CONSTRAINT fk_ab_experiments_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ab_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  experiment_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  allocation_percent DECIMAL(5,2) NOT NULL,
  payload_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_ab_variants_experiment (experiment_id),
  CONSTRAINT fk_ab_variants_experiment FOREIGN KEY (experiment_id) REFERENCES ab_experiments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ab_assignments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  experiment_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  anonymous_id CHAR(36) NULL,
  assigned_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ab_assignment_user (experiment_id, user_id),
  UNIQUE KEY uq_ab_assignment_anon (experiment_id, anonymous_id),
  CONSTRAINT fk_ab_assignments_experiment FOREIGN KEY (experiment_id) REFERENCES ab_experiments(id) ON DELETE CASCADE,
  CONSTRAINT fk_ab_assignments_variant FOREIGN KEY (variant_id) REFERENCES ab_variants(id) ON DELETE CASCADE,
  CONSTRAINT fk_ab_assignments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ab_metrics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  experiment_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NOT NULL,
  metric_name VARCHAR(100) NOT NULL,
  metric_value DECIMAL(20,6) NOT NULL,
  occurred_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ab_metrics_variant_time (variant_id, occurred_at),
  CONSTRAINT fk_ab_metrics_experiment FOREIGN KEY (experiment_id) REFERENCES ab_experiments(id) ON DELETE CASCADE,
  CONSTRAINT fk_ab_metrics_variant FOREIGN KEY (variant_id) REFERENCES ab_variants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS heatmap_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  anonymous_id CHAR(36) NULL,
  user_id BIGINT UNSIGNED NULL,
  page_url VARCHAR(2048) NOT NULL,
  event_type VARCHAR(32) NOT NULL,
  x INT NULL,
  y INT NULL,
  viewport_width INT NULL,
  viewport_height INT NULL,
  scroll_percent DECIMAL(5,2) NULL,
  occurred_at DATETIME(6) NOT NULL,
  metadata_json JSON NULL,
  PRIMARY KEY (id),
  KEY idx_heatmap_page_time (page_url(255), occurred_at),
  CONSTRAINT fk_heatmap_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS embed_domains (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  organization_id BIGINT UNSIGNED NOT NULL,
  domain VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_embed_domains_org_domain (organization_id, domain),
  CONSTRAINT fk_embed_domains_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhooks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  url VARCHAR(2048) NOT NULL,
  secret_encrypted TEXT NOT NULL,
  events_json JSON NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  retry_limit INT UNSIGNED NOT NULL DEFAULT 10,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhooks_public_id (public_id),
  KEY idx_webhooks_org_active (organization_id, active),
  CONSTRAINT fk_webhooks_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  webhook_id BIGINT UNSIGNED NOT NULL,
  delivery_id CHAR(36) NOT NULL,
  event_name VARCHAR(100) NOT NULL,
  payload_json JSON NOT NULL,
  status_code SMALLINT NULL,
  attempt INT UNSIGNED NOT NULL DEFAULT 1,
  response_body MEDIUMTEXT NULL,
  error_message TEXT NULL,
  next_retry_at DATETIME(6) NULL,
  delivered_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_delivery (webhook_id, delivery_id),
  KEY idx_webhook_delivery_retry (next_retry_at),
  CONSTRAINT fk_webhook_deliveries_webhook FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(64) NOT NULL,
  provider_event_id VARCHAR(255) NOT NULL,
  event_name VARCHAR(100) NOT NULL,
  payload_json JSON NOT NULL,
  processed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_events_provider_id (provider, provider_event_id),
  KEY idx_webhook_events_processed (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  key_prefix VARCHAR(20) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  scopes_json JSON NULL,
  expires_at DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_keys_public_id (public_id),
  UNIQUE KEY uq_api_keys_hash (key_hash),
  CONSTRAINT fk_api_keys_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idempotency_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope VARCHAR(100) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  response_status SMALLINT NULL,
  response_body MEDIUMTEXT NULL,
  locked_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idempotency_scope_hash (scope, key_hash),
  KEY idx_idempotency_expire (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ip_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address VARBINARY(16) NULL,
  cidr VARCHAR(64) NULL,
  rule_type VARCHAR(16) NOT NULL,
  scope VARCHAR(32) NOT NULL DEFAULT 'global',
  active TINYINT(1) NOT NULL DEFAULT 1,
  reason VARCHAR(500) NULL,
  expires_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ip_rules_scope_active (scope, active),
  KEY idx_ip_rules_expire (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  version VARCHAR(50) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  manifest_json JSON NOT NULL,
  installed_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_modules_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope VARCHAR(100) NOT NULL,
  setting_key VARCHAR(255) NOT NULL,
  value_json JSON NULL,
  encrypted TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_scope_key (scope, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  organization_id BIGINT UNSIGNED NULL,
  action VARCHAR(150) NOT NULL,
  entity_type VARCHAR(100) NULL,
  entity_id BIGINT UNSIGNED NULL,
  old_values_json JSON NULL,
  new_values_json JSON NULL,
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(1024) NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_audit_org_time (organization_id, created_at),
  KEY idx_audit_entity_time (entity_type, entity_id, created_at),
  KEY idx_audit_user_time (user_id, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ===========================================================================
-- 009 integrity hardening
-- ===========================================================================
-- These checks used to sit here as a "Recommended application-level CHECKS"
-- comment — i.e. they were not enforced at all. Probing this schema on a real
-- MySQL 8.4.11 showed the database accepting `available_quantity = -1`,
-- `tickets.status = 'nonsense'` and mutation of a published hall schema.
-- MySQL 8.0.16+ can reject these itself, so it now does.
--
-- Only values enumerated by the specification (or by the comment that used to
-- live here) are constrained. Where the spec is silent — notably
-- inventory_items.status and orders.payment_status — no CHECK is added.
--
-- Kept inline so this file and migrations/001..009 stay equivalent.
-- Full rationale: migrations/009_integrity_hardening.sql, docs/REVIEW-spec-bundle.md §3.1, §3.3
-- ---------------------------------------------------------------------------

-- ------------------------------------------------------------ inventory
ALTER TABLE inventory_items
  ADD CONSTRAINT ck_inventory_available_qty CHECK (available_quantity BETWEEN 0 AND capacity),
  ADD CONSTRAINT ck_inventory_type          CHECK (type IN ('seat','standing')),
  ADD CONSTRAINT ck_inventory_price         CHECK (price_amount >= 0),
  ADD CONSTRAINT ck_inventory_seat_capacity CHECK (type <> 'seat' OR capacity = 1);

ALTER TABLE inventory_items
  ADD CONSTRAINT ck_inventory_target CHECK (
       (type = 'seat'     AND seat_id IS NOT NULL     AND standing_zone_id IS NULL)
    OR (type = 'standing' AND standing_zone_id IS NOT NULL AND seat_id IS NULL)
  );

-- ------------------------------------------------------------ hall model
ALTER TABLE sectors
  ADD CONSTRAINT ck_sectors_type CHECK (type IN ('seated','standing','mixed'));

ALTER TABLE hall_schema_versions
  ADD CONSTRAINT ck_schema_status CHECK (status IN ('draft','published','archived'));

-- §25 enumerates the session lifecycle explicitly.
ALTER TABLE sessions
  ADD CONSTRAINT ck_sessions_status CHECK (status IN (
    'draft','scheduled','on_sale','sold_out','closed','completed','cancelled'));

ALTER TABLE seats
  ADD CONSTRAINT ck_seats_type   CHECK (type IN ('standard','vip','wheelchair','companion','custom')),
  ADD CONSTRAINT ck_seats_status CHECK (status IN ('active','blocked','disabled'));

ALTER TABLE standing_zones
  ADD CONSTRAINT ck_standing_capacity CHECK (capacity > 0);

-- ------------------------------------------------------------ holds
ALTER TABLE seat_holds
  ADD CONSTRAINT ck_holds_quantity CHECK (quantity > 0);

-- ------------------------------------------------------------ sales
ALTER TABLE orders
  ADD CONSTRAINT ck_orders_status CHECK (status IN (
    'pending','awaiting_payment','payment_failed','paid','cancelled','expired',
    'partially_refunded','refunded')),
  ADD CONSTRAINT ck_orders_amounts CHECK (
    subtotal_amount >= 0 AND discount_amount >= 0 AND fee_amount >= 0 AND total_amount >= 0);

ALTER TABLE order_items
  ADD CONSTRAINT ck_order_items_quantity CHECK (quantity > 0),
  ADD CONSTRAINT ck_order_items_amounts  CHECK (unit_price >= 0 AND total_amount >= 0);

ALTER TABLE cart_items
  ADD CONSTRAINT ck_cart_items_quantity CHECK (quantity > 0);

-- ------------------------------------------------------------ payments
ALTER TABLE payments
  ADD CONSTRAINT ck_payments_status CHECK (status IN (
    'pending','waiting_for_capture','succeeded','canceled','failed')),
  ADD CONSTRAINT ck_payments_amount CHECK (amount >= 0);

ALTER TABLE refunds
  ADD CONSTRAINT ck_refunds_status CHECK (status IN ('requested','processing','succeeded','failed')),
  ADD CONSTRAINT ck_refunds_amount CHECK (amount >= 0);

-- ------------------------------------------------------------ tickets
ALTER TABLE tickets
  ADD CONSTRAINT ck_tickets_status CHECK (status IN ('issued','used','cancelled','refunded','expired')),
  ADD CONSTRAINT ck_tickets_index  CHECK (ticket_index >= 1),
  ADD CONSTRAINT ck_tickets_terminal_exclusive CHECK (
    NOT (used_at IS NOT NULL AND (cancelled_at IS NOT NULL OR refunded_at IS NOT NULL))
  );

-- ------------------------------------------------- published schema immutability
-- §20 / §98: a published version is immutable. CHECK cannot reference OLD, so
-- this needs a trigger. Changing `status` (publish/archive) is still allowed.
DELIMITER //
DROP TRIGGER IF EXISTS trg_schema_version_immutable //
CREATE TRIGGER trg_schema_version_immutable
BEFORE UPDATE ON hall_schema_versions
FOR EACH ROW
BEGIN
  -- Any version that has left `draft` is frozen (covers `archived` too).
  IF OLD.status IN ('published', 'archived') THEN
    IF NOT (NEW.schema_json <=> OLD.schema_json)
       OR NOT (NEW.version <=> OLD.version)
       OR NOT (NEW.hall_id <=> OLD.hall_id)
       OR NOT (NEW.width <=> OLD.width)
       OR NOT (NEW.height <=> OLD.height)
       OR NOT (NEW.background_url <=> OLD.background_url)
    THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Published hall schema versions are immutable (spec 20/98). Duplicate to a new version instead.';
    END IF;
  END IF;
END //
DELIMITER ;
-- ============================================================ 010 — ТЗ gaps
-- Closes the six functional gaps documented in docs/REVIEW-spec-bundle.md §3.10.
-- Each block cites the source requirement in `Мысли.md`.
--
--   1. promo codes                    §86
--   2. venue_translations / page_translations   §73
--   3. media library                  §73 (optional per §2336)
--   4. offline bundles for Checker    §43
--   5. user_roles (many-to-many)      §73
--   6. ticket status `revoked`        §43 / §44
--
-- Conventions kept identical to 001..009: BIGINT UNSIGNED surrogate keys,
-- DATETIME(6), money as integer minor units, utf8mb4_unicode_ci, and the
-- uq_ / idx_ / fk_ / ck_ naming scheme enforced by 009.

-- ---------------------------------------------------------- 1. promo codes (§86)
-- §86 lists the conditions a code may carry: fixed / percent / first_purchase /
-- event / category / date / quantity. They map onto discount_type, scope,
-- the validity window and the redemption counters below.
CREATE TABLE IF NOT EXISTS promo_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  discount_type VARCHAR(16) NOT NULL DEFAULT 'percent',
  -- Money is integer minor units (kopecks). Only one of the two value columns
  -- is meaningful, chosen by discount_type; the other stays 0.
  value_amount BIGINT NOT NULL DEFAULT 0,
  value_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  scope VARCHAR(32) NOT NULL DEFAULT 'all',
  event_id BIGINT UNSIGNED NULL,
  event_category_id BIGINT UNSIGNED NULL,
  min_order_amount BIGINT NOT NULL DEFAULT 0,
  max_redemptions INT UNSIGNED NULL,
  per_user_limit INT UNSIGNED NOT NULL DEFAULT 1,
  redemptions_count INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(32) NOT NULL DEFAULT 'active',
  valid_from DATETIME(6) NULL,
  valid_until DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  deleted_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_promo_codes_public_id (public_id),
  UNIQUE KEY uq_promo_codes_org_code (organization_id, code),
  KEY idx_promo_codes_scope_event (scope, event_id),
  KEY idx_promo_codes_window (valid_from, valid_until),
  CONSTRAINT fk_promo_codes_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_promo_codes_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_promo_codes_category FOREIGN KEY (event_category_id) REFERENCES event_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The usage counter §86 implies: without it a code cannot honour `quantity`
-- or `first_purchase`, and there is no audit trail for a discount.
CREATE TABLE IF NOT EXISTS promo_code_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  promo_code_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  discount_amount BIGINT NOT NULL DEFAULT 0,
  redeemed_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_promo_redemptions_order_code (order_id, promo_code_id),
  KEY idx_promo_redemptions_code (promo_code_id),
  KEY idx_promo_redemptions_user (user_id),
  CONSTRAINT fk_promo_redemptions_code FOREIGN KEY (promo_code_id) REFERENCES promo_codes(id) ON DELETE RESTRICT,
  CONSTRAINT fk_promo_redemptions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_promo_redemptions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- orders.discount_amount already existed but nothing could produce it.
ALTER TABLE orders
  ADD COLUMN promo_code_id BIGINT UNSIGNED NULL,
  ADD KEY idx_orders_promo (promo_code_id),
  ADD CONSTRAINT fk_orders_promo FOREIGN KEY (promo_code_id) REFERENCES promo_codes(id) ON DELETE SET NULL;

-- ---------------------------------------------------------- 2. translations (§73)
-- §73 declares localization end-to-end, including localized URLs
-- (/ru/events/... , /en/events/...). event_translations already existed;
-- venues and pages were missing.
CREATE TABLE IF NOT EXISTS venue_translations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  venue_id BIGINT UNSIGNED NOT NULL,
  locale VARCHAR(10) NOT NULL,
  name VARCHAR(255) NULL,
  description TEXT NULL,
  address VARCHAR(500) NULL,
  seo_title VARCHAR(500) NULL,
  seo_description VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_venue_translations (venue_id, locale),
  CONSTRAINT fk_venue_translations_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS page_translations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  page_id BIGINT UNSIGNED NOT NULL,
  locale VARCHAR(10) NOT NULL,
  title VARCHAR(500) NULL,
  content LONGTEXT NULL,
  -- Localized URL segment: §73 routes /{locale}/... so the slug is per-locale.
  slug VARCHAR(255) NULL,
  seo_title VARCHAR(500) NULL,
  seo_description VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_page_translations (page_id, locale),
  KEY idx_page_translations_locale_slug (locale, slug),
  CONSTRAINT fk_page_translations_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 3. media library (§73)
-- Today only logo / poster / cover URL columns exist, so uploads are not
-- accounted for and galleries have no order, alt-text or size variants.
CREATE TABLE IF NOT EXISTS media_assets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NULL,
  disk VARCHAR(64) NOT NULL DEFAULT 'local',
  path VARCHAR(1024) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  mime_type VARCHAR(150) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  checksum CHAR(64) NULL,
  title VARCHAR(500) NULL,
  alt_text VARCHAR(500) NULL,
  -- thumb / medium / large derivatives, so a gallery can serve the right size.
  variants_json JSON NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  deleted_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_assets_public_id (public_id),
  KEY idx_media_assets_org (organization_id),
  KEY idx_media_assets_checksum (checksum),
  CONSTRAINT fk_media_assets_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_media_assets_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Polymorphic on purpose: one asset can serve an event poster, a venue photo
-- and a CMS page. `role` + `position` give the gallery its ordering.
CREATE TABLE IF NOT EXISTS media_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  media_asset_id BIGINT UNSIGNED NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(64) NOT NULL DEFAULT 'gallery',
  position INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_media_links (entity_type, entity_id, media_asset_id, role),
  KEY idx_media_links_entity (entity_type, entity_id),
  KEY idx_media_links_asset (media_asset_id),
  CONSTRAINT fk_media_links_asset FOREIGN KEY (media_asset_id) REFERENCES media_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 4. offline bundles (§43)
-- §43: before a session the Android Checker downloads the event, the session,
-- the list of valid AND revoked tickets, and the public key. checkin_devices
-- records the device; nothing recorded what it was actually handed.
CREATE TABLE IF NOT EXISTS offline_bundles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  checkin_device_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NULL,
  bundle_hash CHAR(64) NOT NULL,
  schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  public_key_fingerprint VARCHAR(128) NULL,
  ticket_count INT UNSIGNED NOT NULL DEFAULT 0,
  revoked_count INT UNSIGNED NOT NULL DEFAULT 0,
  -- ПII: the bundle carries ticket public ids, numbers and statuses only.
  -- Holder names are never included — same rule as the QR payload (§43/§44).
  payload_json JSON NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'generated',
  generated_at DATETIME(6) NOT NULL,
  downloaded_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_offline_bundles_public_id (public_id),
  -- Uniqueness is per (bundle_hash, check-in device), NOT global. A global
  -- UNIQUE on bundle_hash alone let the first device download a bundle and made
  -- every other device fail with a duplicate-key error, although §43 expects the
  -- same bundle content on all of them. This is the spec side of
  -- 2026_09_20_001200_fix_offline_bundles_unique_constraint.
  UNIQUE KEY uq_offline_bundles_hash_device (bundle_hash, checkin_device_id),
  KEY idx_offline_bundles_device_hash (checkin_device_id, bundle_hash),
  KEY idx_offline_bundles_device (checkin_device_id, generated_at),
  KEY idx_offline_bundles_session (session_id),
  CONSTRAINT fk_offline_bundles_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_offline_bundles_device FOREIGN KEY (checkin_device_id) REFERENCES checkin_devices(id) ON DELETE CASCADE,
  CONSTRAINT fk_offline_bundles_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_offline_bundles_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 5. user_roles (§73)
-- user_organization.role_id allows exactly one role per (user, organization).
-- A user who is both an event manager and a cashier needs a shared table.
CREATE TABLE IF NOT EXISTS user_roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  organization_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_roles (user_id, organization_id, role_id),
  KEY idx_user_roles_org_role (organization_id, role_id),
  KEY idx_user_roles_role (role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_user_roles_grantor FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- 6. ticket `revoked` (§43/§44)
-- §43 requires the offline list of revoked tickets; §44 resolves offline scan
-- conflicts by revocation. `cancelled` means a customer/merchant cancellation
-- (usually with a refund); `revoked` means fraud, chargeback or duplicate
-- resolution, often WITHOUT a refund. They are not the same state.
ALTER TABLE tickets
  ADD COLUMN revoked_at DATETIME(6) NULL,
  ADD COLUMN revoked_reason VARCHAR(100) NULL;

-- 009 pinned the status list and therefore rejected `revoked`; widen it here.
ALTER TABLE tickets
  DROP CONSTRAINT ck_tickets_status;

ALTER TABLE tickets
  ADD CONSTRAINT ck_tickets_status CHECK (status IN ('issued','used','cancelled','refunded','expired','revoked'));

-- ck_tickets_terminal_exclusive is deliberately left untouched: a ticket that
-- was admitted and later revoked (chargeback after entry) is a legitimate and
-- reportable situation, unlike refund-after-entry.

-- ---------------------------------------------------------- integrity for the new tables
ALTER TABLE promo_codes
  ADD CONSTRAINT ck_promo_codes_type CHECK (discount_type IN ('fixed','percent')),
  ADD CONSTRAINT ck_promo_codes_scope CHECK (scope IN ('all','event','category','first_purchase')),
  ADD CONSTRAINT ck_promo_codes_percent CHECK (value_percent >= 0 AND value_percent <= 100),
  ADD CONSTRAINT ck_promo_codes_amounts CHECK (value_amount >= 0 AND min_order_amount >= 0),
  ADD CONSTRAINT ck_promo_codes_window CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from),
  ADD CONSTRAINT ck_promo_codes_limit CHECK (per_user_limit >= 1);

ALTER TABLE promo_code_redemptions
  ADD CONSTRAINT ck_promo_redemptions_amount CHECK (discount_amount >= 0);

ALTER TABLE media_assets
  ADD CONSTRAINT ck_media_assets_size CHECK (size_bytes >= 0);

ALTER TABLE media_links
  ADD CONSTRAINT ck_media_links_position CHECK (position >= 0);

ALTER TABLE offline_bundles
  ADD CONSTRAINT ck_offline_bundles_counts CHECK (ticket_count >= 0 AND revoked_count >= 0),
  ADD CONSTRAINT ck_offline_bundles_window CHECK (expires_at IS NULL OR expires_at >= generated_at);

-- ============================================================ 011 — checkout fixes (D5, B2, A6)
-- Mirrors the applied Laravel migrations:
--   database/migrations/2026_09_29_000100_add_cart_token_and_order_cart_id.php
--   database/migrations/2026_09_29_000200_add_order_customer_name.php
--   database/migrations/2026_09_29_000300_add_qr_payload_to_tickets.php
--   database/migrations/2026_09_30_000100_add_session_event_to_orders.php
--
--   1. carts.cart_token + orders.cart_id  — D5 «корзина на сеанс»: корзина
--      ключуется парой (cart_token, session_id), заказ помнит корзину, из
--      которой вырос (чинит validateHoldsForOrder).
--   2. orders.customer_name               — B2: витрина собирала имя покупателя,
--      но писать его было некуда.
--   3. tickets.qr_payload                 — A6: QR-картинка кодирует подписанный
--      пэйлоад QrSigner («NB1.<id>.<token>.<sig>»), qr_token_hash остаётся
--      якорем проверки на чек-ине.
--   4. orders.session_id / event_id       — A6: выпуск билетов требует их,
--      резолвятся из корзины (carts.session_id → sessions.event_id).

ALTER TABLE carts
  ADD COLUMN cart_token VARCHAR(64) NULL AFTER user_id;

-- Уникальность корзины — только среди АКТИВНЫХ (инвариант D5: два параллельных
-- покупателя одного сеанса работают в разных корзинах). Ключ
-- uq_carts_token_session_status, стоявший здесь раньше, запрещал больше одной
-- корзины на пару (токен, сеанс) В КАЖДОМ статусе: первая покупка переводила
-- корзину в `converted`, вторая создавала активную и на оформлении тоже
-- пыталась стать `converted` — и получала 1062 Duplicate entry, то есть сырой
-- 500 на обычном «купить ещё раз». Исторические `converted`/`abandoned`
-- ограничивать нечем: их может быть сколько угодно.
--
-- Частичных индексов MySQL не умеет, поэтому «только active» выражено
-- вычисляемой колонкой: у активной корзины ключ <токен>-<сеанс>, у всех прочих
-- NULL, а уникальный индекс допускает сколько угодно NULL.
-- VIRTUAL, а не STORED: STORED пересобирает таблицу (ALGORITHM=COPY) и заново
-- создаёт внешние ключи, а это падает с ERROR 1215 (Cannot add foreign key
-- constraint). Проверено обеими формами на живом стенде.
--
-- Это spec-сторона 2026_10_05_000200_constrain_carts_unique_to_active.
ALTER TABLE carts
  ADD COLUMN active_cart_key VARCHAR(128)
    GENERATED ALWAYS AS (
      IF(status = 'active', CONCAT(COALESCE(cart_token, ''), '-', session_id), NULL)
    ) VIRTUAL,
  ADD UNIQUE KEY uq_carts_active (active_cart_key);

ALTER TABLE orders
  ADD COLUMN session_id BIGINT UNSIGNED NULL AFTER user_id,
  ADD COLUMN event_id BIGINT UNSIGNED NULL AFTER session_id,
  ADD COLUMN customer_name VARCHAR(255) NULL AFTER customer_email,
  ADD COLUMN cart_id BIGINT UNSIGNED NULL AFTER promo_code_id,
  ADD KEY idx_orders_session (session_id),
  ADD KEY idx_orders_event (event_id),
  ADD KEY idx_orders_cart (cart_id);

ALTER TABLE tickets
  ADD COLUMN qr_payload VARCHAR(255) NULL AFTER qr_token_hash;


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

-- ============================================================ 013 — users.status dictionary
-- Mirrors nabilet_core_spec/migrations/013_users_status_check.sql and the applied
-- Laravel migration 2026_10_12_000100_add_users_status_check.php.
--
-- `users.status` had no CHECK and no vocabulary outside one rule inside
-- `UserController::update()`, so `status = 'banned'` did not stop anyone from
-- logging in — sessions were revoked and the same password bought a fresh one —
-- and any other writer could store an arbitrary string. The values below are the
-- set the API, the admin UI and `UserSessionRevocationTest` already agreed on.
-- Full reasoning in the split file.
ALTER TABLE users
  ADD CONSTRAINT ck_users_status CHECK (status IN ('active','inactive','banned'));

-- ============================================================ 014 — inventory_items.status dictionary
-- Mirrors nabilet_core_spec/migrations/014_inventory_status_check.sql and the applied
-- Laravel migration 2026_10_12_000200_add_inventory_items_status_check.php.
--
-- `inventory_items.status` had no CHECK, and `InventoryItemStateMachine` omitted
-- `sold_out` while `CartItemService` writes it whenever capacity is exhausted —
-- which for a seat (capacity = 1) is the ordinary add-to-cart path. Because the
-- machine had no such state, `PaymentService::markInventorySoldForOrder()` skipped
-- the `→ sold` write for a PAID position, which then stayed `sold_out` forever.
-- The six values below are what the writers store and what the storefront already
-- expects (resources/js/lib/seatStatus.ts). Full reasoning in the split file.
ALTER TABLE inventory_items
  ADD CONSTRAINT ck_inventory_status CHECK (status IN ('available','held','sold_out','sold','blocked','disabled'));

-- ============================================================ 015 — events.status dictionary
-- Mirrors nabilet_core_spec/migrations/015_events_status_check.sql and the applied
-- Laravel migration 2026_10_12_000300_add_events_status_check.php.
--
-- The last column backed by a PHP state machine with no CHECK in the schema:
-- `tools/verify-state-machines.php` reported "no CHECK on status — machine is
-- unenforced" on every run. The code was already disciplined (every writer goes
-- through `EventStatus` constants or `Event::PUBLISHED`), so the database only
-- had to stop being silent. `EventStateMachine` said as much itself: the
-- `cancelled` spelling was pinned "by convention (and by tests) rather than by
-- the database". Full reasoning in the split file.
ALTER TABLE events
  ADD CONSTRAINT ck_events_status CHECK (status IN ('draft','scheduled','published','completed','cancelled','archived'));

SET FOREIGN_KEY_CHECKS = 1;
--
-- `login_logs` was written from exactly one place — `SessionIssuer::auditLogout()`
-- — i.e. by the logout path. A login row could only be told apart from a logout
-- row indirectly: a login has `failure_code` set, a logout does not. That holds
-- exactly until the first writer logs a successful login with `failure_code =
-- NULL`, after which the two events are indistinguishable. The event column is
-- storage for what the domain already distinguishes (`login`, `logout`,
-- `login_failed`), and the CHECK locks the set so a row nobody can classify
-- cannot be written. Full reasoning in `016_login_logs_event.sql`.
ALTER TABLE login_logs
  ADD COLUMN event VARCHAR(32) NOT NULL DEFAULT 'login' AFTER success;

ALTER TABLE login_logs
  ADD CONSTRAINT ck_login_logs_event CHECK (event IN ('login','logout','login_failed'));

SET FOREIGN_KEY_CHECKS = 1;
--
-- §3.11 lists 17 status columns without a CHECK. They cannot be treated as one
-- list: behind some stands a vocabulary the code already wrote and already
-- enforces (a domain class, a service constant, a request rule), behind others
-- there is no vocabulary at all — inventing one here would be inventing an
-- agreement. This block locks only the first kind. Every value was already an
-- application rule before it became a database one.
--
-- WHAT STAYS FREE-FORM, ON PURPOSE:
--   `idempotency_keys.response_status` — an HTTP status code (100…599); the
--     vocabulary belongs to IANA, not to the application.
--   `payment_transactions.status` — the payment provider's own vocabulary (it
--     also lands in the raw payload); pinning a third party's vocabulary in the
--     spec is how you break on a provider switch.
--   `pages.status`, `promo_codes.status`, `halls.status`, `venues.status`,
--     `event_categories.status`, `event_dates.status`, `offline_bundles.status` —
--     values are only ever COMPARED ('active', 'published', 'draft',
--     'scheduled'); no writer validates them and no class calls itself a
--     vocabulary. A CHECK here would be a made-up convention, recorded in
--     §3.11/§3.25 as a product decision instead.
--   `ab_experiments.status` — the vocabulary is declared in the model docblock
--     ('draft' | 'running' | 'completed'), but there is no write path at all
--     (the A/B module has no HTTP layer), so the docblock is the only
--     declaration the column has.
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

ALTER TABLE orders
  ADD CONSTRAINT ck_orders_payment_status CHECK (payment_status IN ('pending','succeeded'));

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================ 018 — loyalty foundation (favorites, follows, follow log)
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

-- ============================================================ 019 — bonus ledger (accounts, transactions, orders.bonus_amount)
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

-- ---------------------------------------------------------- 020: уровни «Клуба зрителей» (фаза 3)
-- Конфиг уровней: Старт/Friend/VIP по числу оплаченных заказов; текущий уровень
-- покупателя — колонка на `users`, пересчитываемая обсервером при смене статуса
-- заказа. ЗЕРКАЛО: database/migrations/2026_10_14_020000_create_loyalty_levels.php.
CREATE TABLE IF NOT EXISTS loyalty_levels (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  slug VARCHAR(32) NOT NULL,
  name VARCHAR(64) NOT NULL,
  min_paid_orders INT NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_loyalty_levels_public_id (public_id),
  UNIQUE KEY uq_loyalty_levels_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD COLUMN loyalty_level_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_users_loyalty_level FOREIGN KEY (loyalty_level_id)
    REFERENCES loyalty_levels(id) ON DELETE SET NULL;

INSERT INTO loyalty_levels (public_id, slug, name, min_paid_orders, sort_order, created_at, updated_at) VALUES
  ('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'start', 'Старт', 0, 1, NOW(6), NOW(6)),
  ('01ARZ3NDEKTSV4RRFFQ69G5FAW', 'friend', 'Друг', 3, 2, NOW(6), NOW(6)),
  ('01ARZ3NDEKTSV4RRFFQ69G5FAX', 'vip', 'VIP', 10, 3, NOW(6), NOW(6));

SET FOREIGN_KEY_CHECKS = 1;

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------- 021: реферальная программа (фаза 4)
-- Код приглашения — свойство приглашающего (`referral_codes`: одна строка на
-- человека, `UNIQUE (user_id)`), приглашение — строка на каждого друга
-- (`referrals`: `UNIQUE (inviter_user_id, invited_email)` — инвариант
-- идемпотентности). Друг определяется АДРЕСОМ, а не аккаунтом: покупатель по
-- умолчанию — гость, и ссылку `/r/{code}` открывают до регистрации;
-- `invited_user_id` — необязательное уточнение на потом (SET NULL). Статус
-- `pending → registered → rewarded` со словарём в CHECK; `bonus_paid_at`
-- фиксирует момент награды. Словарь причин журнала расширен `referral_inviter`
-- и `referral_invited` — CHECK переиздаётся (MySQL 8 не умеет ALTER CHECK, а
-- снятие безопасно: новое условие — надмножество старого). ЗЕРКАЛО:
-- database/migrations/2026_10_14_030000_create_referrals.php.
CREATE TABLE IF NOT EXISTS referral_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(16) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_referral_codes_public_id (public_id),
  UNIQUE KEY uq_referral_codes_user (user_id),
  UNIQUE KEY uq_referral_codes_code (code),
  CONSTRAINT fk_referral_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referrals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(26) NOT NULL,
  inviter_user_id BIGINT UNSIGNED NOT NULL,
  invited_email VARCHAR(255) NOT NULL,
  invited_user_id BIGINT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  bonus_paid_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_referrals_public_id (public_id),
  UNIQUE KEY uq_referrals_inviter_email (inviter_user_id, invited_email),
  KEY idx_referrals_invited_user (invited_user_id),
  CONSTRAINT fk_referrals_inviter FOREIGN KEY (inviter_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_referrals_invited_user FOREIGN KEY (invited_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE referrals
  ADD CONSTRAINT ck_referrals_status CHECK (status IN ('pending','registered','rewarded'));

ALTER TABLE bonus_transactions DROP CONSTRAINT ck_bonus_transactions_reason;

ALTER TABLE bonus_transactions
  ADD CONSTRAINT ck_bonus_transactions_reason CHECK (reason IN (
    'accrual','registration','first_purchase','spend','refund','expiry',
    'referral_inviter','referral_invited'));

SET FOREIGN_KEY_CHECKS = 1;
