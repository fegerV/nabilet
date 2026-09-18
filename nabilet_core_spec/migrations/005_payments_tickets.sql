-- NABILET Core v1.0
-- MySQL 8.4+
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;
