-- NABILET Core v1.0
-- MySQL 8.4+
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;
