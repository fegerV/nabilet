-- NABILET Core v1.0
-- MySQL 8.4+
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

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
