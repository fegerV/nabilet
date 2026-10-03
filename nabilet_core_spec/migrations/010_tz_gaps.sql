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
  UNIQUE KEY uq_offline_bundles_hash (bundle_hash),
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
