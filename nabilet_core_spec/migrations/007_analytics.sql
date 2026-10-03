-- NABILET Core v1.0
-- MySQL 8.4+
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;
