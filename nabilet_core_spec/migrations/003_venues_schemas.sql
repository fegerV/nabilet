-- NABILET Core v1.0
-- MySQL 8.4+
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

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

SET FOREIGN_KEY_CHECKS = 1;
