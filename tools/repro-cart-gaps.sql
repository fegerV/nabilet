-- Reproduction for REVIEW-spec-bundle.md §3.16 — the cart gaps.
--
-- Every defect in that section was observed by running this against a real
-- MySQL 8.4 instance with the spec bundle loaded. It is kept in the repository
-- because "the schema cannot do this" is a claim, and a claim without a script
-- is worth exactly as much as the memory of the person who made it.
--
--   docker exec -i nabilet-mysql mysql -uroot -prootpass \
--     -e "DROP DATABASE IF EXISTS nabilet_p2; CREATE DATABASE nabilet_p2;"
--   docker exec -i nabilet-mysql mysql -uroot -prootpass nabilet_p2 < migrations.sql
--   docker exec -i nabilet-mysql mysql -uroot -prootpass nabilet_p2 --table < tools/repro-cart-gaps.sql
--
-- Expected: every INSERT/UPDATE below is ACCEPTED. That is the finding.
-- The only statement the schema refuses is quantity = 0 (ck_cart_items_quantity),
-- which is why a zero line is listed as NOT a defect.

SET @now = NOW(6);

INSERT INTO organizations (id, public_id, name, slug, created_at, updated_at)
VALUES (1, 'org_aaaaaaaaaaaaaaaaaaaaaa', 'Org', 'org', @now, @now);

INSERT INTO venues (id, public_id, organization_id, name, slug, created_at, updated_at)
VALUES (1, 'ven_aaaaaaaaaaaaaaaaaaaaaa', 1, 'Venue', 'venue', @now, @now);

INSERT INTO halls (id, public_id, venue_id, name, created_at, updated_at)
VALUES (1, 'hal_aaaaaaaaaaaaaaaaaaaaaa', 1, 'Hall', @now, @now);

INSERT INTO hall_schema_versions (id, public_id, hall_id, version, status, schema_json, published_at, created_at, updated_at)
VALUES (1, 'hsv_aaaaaaaaaaaaaaaaaaaaaa', 1, 1, 'published', '{}', @now, @now, @now);

INSERT INTO events (id, public_id, organization_id, title, slug, created_at, updated_at)
VALUES (1, 'evt_aaaaaaaaaaaaaaaaaaaaaa', 1, 'Event', 'event', @now, @now);

-- Two DIFFERENT sessions of the same event.
INSERT INTO sessions (id, public_id, event_id, venue_id, hall_id, schema_version_id, starts_at, created_at, updated_at)
VALUES
  (1, 'ses_aaaaaaaaaaaaaaaaaaaaaa', 1, 1, 1, 1, '2026-12-01 19:00:00', @now, @now),
  (2, 'ses_bbbbbbbbbbbbbbbbbbbbbb', 1, 1, 1, 1, '2026-12-02 19:00:00', @now, @now);

INSERT INTO users (id, public_id, email, created_at, updated_at)
VALUES (1, 'usr_aaaaaaaaaaaaaaaaaaaaaa', 'a@example.com', @now, @now);

INSERT INTO sectors (id, public_id, schema_version_id, name, code, created_at, updated_at)
VALUES (1, 'sec_aaaaaaaaaaaaaaaaaaaaaa', 1, 'Sector', 'S1', @now, @now);

INSERT INTO hall_rows (id, public_id, sector_id, number, created_at, updated_at)
VALUES (1, 'row_aaaaaaaaaaaaaaaaaaaaaa', 1, '1', @now, @now);

INSERT INTO seats (id, public_id, row_id, number, created_at, updated_at)
VALUES (1, 'sea_aaaaaaaaaaaaaaaaaaaaaa', 1, '1', @now, @now);

INSERT INTO standing_zones (id, public_id, sector_id, name, capacity, price_amount, created_at, updated_at)
VALUES (1, 'stz_aaaaaaaaaaaaaaaaaaaaaa', 1, 'Standing', 50, 100000, @now, @now);

-- Inventory: item 10 belongs to session 1, item 20 belongs to session 2.
INSERT INTO inventory_items (id, public_id, session_id, type, seat_id, standing_zone_id, price_amount, capacity, available_quantity, status, created_at, updated_at)
VALUES
  (10, 'inv_aaaaaaaaaaaaaaaaaaaaaa', 1, 'seat',     1,    NULL, 100000, 1,  1,  'available', @now, @now),
  (20, 'inv_bbbbbbbbbbbbbbbbbbbbbb', 2, 'standing', NULL, 1,    100000, 50, 50, 'available', @now, @now);

-- A cart for session 1.
INSERT INTO carts (id, public_id, user_id, session_id, status, expires_at, created_at, updated_at)
VALUES (1, 'crt_aaaaaaaaaaaaaaaaaaaaaa', 1, 1, 'active', '2026-11-30 00:00:00', @now, @now);

-- ── 1. the headline gap ────────────────────────────────────────────────────
SELECT '--- 1. cart for session 1, item from session 2:' AS probe;
INSERT INTO cart_items (id, cart_id, inventory_item_id, quantity, unit_price, total_price, created_at, updated_at)
VALUES (1, 1, 20, 2, 100000, 200000, @now, @now);
SELECT 'INSERTED: cart(session 1) now holds inventory(session 2)' AS result;

SELECT c.id AS cart_id, c.session_id AS cart_session, ci.inventory_item_id, ii.session_id AS item_session
FROM cart_items ci
JOIN carts c ON c.id = ci.cart_id
JOIN inventory_items ii ON ii.id = ci.inventory_item_id;

-- ── 2. stored total unrelated to arithmetic ────────────────────────────────
SELECT '--- 2. total_price that disagrees with unit_price * quantity:' AS probe;
UPDATE cart_items SET total_price = 1 WHERE id = 1;
SELECT 'total_price=1 accepted while unit_price*quantity=200000' AS result;

-- ── 3. no CHECK on carts.status ────────────────────────────────────────────
SELECT '--- 3. cart status with no CHECK:' AS probe;
UPDATE carts SET status = 'banana' WHERE id = 1;
SELECT 'status=banana accepted' AS result;

-- ── 4. nullable expiry ─────────────────────────────────────────────────────
SELECT '--- 4. cart with no expiry at all (expires_at is NULLable):' AS probe;
UPDATE carts SET status = 'active', expires_at = NULL WHERE id = 1;
SELECT 'NULL expires_at accepted: this cart never expires' AS result;

-- ── 5. expiry unrelated to the performance ─────────────────────────────────
SELECT '--- 5. cart whose session already started:' AS probe;
UPDATE carts SET expires_at = '2027-01-01 00:00:00' WHERE id = 1;
SELECT c.expires_at, s.starts_at,
       CASE WHEN c.expires_at > s.starts_at THEN 'cart outlives the performance it sells' ELSE 'ok' END AS verdict
FROM carts c JOIN sessions s ON s.id = c.session_id WHERE c.id = 1;

-- ── 6. negative money ──────────────────────────────────────────────────────
-- NOTE: quantity > 0 IS constrained (ck_cart_items_quantity), so a zero line is
-- not a gap. Negative money is: unit_price is a signed BIGINT with no CHECK.
SELECT '--- 6. negative money (unit_price is signed BIGINT, no CHECK):' AS probe;
UPDATE cart_items SET quantity = 1, unit_price = -500000, total_price = -500000 WHERE id = 1;
SELECT 'unit_price=-500000 accepted: the house pays the buyer' AS result;
