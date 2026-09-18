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
  UNIQUE KEY uq_events_org_slug (organization_id, slug),
  KEY idx_events_org_status (organization_id, status),
  KEY idx_events_category (category_id),
  KEY idx_events_published (published_at),
  CONSTRAINT fk_events_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_events_category FOREIGN KEY (category_id) REFERENCES event_categories(id) ON DELETE SET NULL
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
  UNIQUE KEY uq_venues_org_slug (organization_id, slug),
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
