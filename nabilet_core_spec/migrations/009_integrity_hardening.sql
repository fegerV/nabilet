-- NABILET Core v1 — 009 integrity hardening
-- MySQL 8.0.16+ (CHECK constraints), tested on 8.4.11
--
-- WHY THIS FILE EXISTS
--   The baseline schema (001..008) leaves every money- and trust-critical
--   invariant enforced only in application code. Probing the baseline against a
--   real MySQL instance showed that the database happily accepts all of these:
--
--       UPDATE inventory_items SET available_quantity = -1;            -- over-sell
--       UPDATE inventory_items SET available_quantity = capacity + 50; -- corruption
--       UPDATE tickets         SET status = 'nonsense';
--       UPDATE hall_schema_versions SET schema_json = '{}' WHERE status = 'published';
--
--   The baseline even admits this in a trailing comment ("Recommended
--   application-level CHECKS"). That is a deliberate choice, but it means a
--   single bug in a service or a stray Eloquent save() corrupts sellable
--   inventory or rewrites the geometry of a sold-out hall silently, with no
--   error anywhere. See docs/REVIEW-spec-bundle.md §3.1 and §3.3.
--
--   MySQL can reject these itself, so it should.
--
-- SCOPE DISCIPLINE
--   Only values that the specification (or the baseline's own trailing comment)
--   enumerates are constrained. Where the spec is silent — notably
--   inventory_items.status and orders.payment_status — no CHECK is added,
--   because inventing an enumeration would be guessing at the design.
--
--   Every constraint is named `ck_*` / `trg_*` so an individual one can be
--   dropped without touching the others:
--       ALTER TABLE inventory_items DROP CONSTRAINT ck_inventory_available_qty;

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ============================================================ inventory
-- The single most important invariant in the system: a sellable unit must never
-- be sold more times than it exists. Without this, an over-sell is silent.
ALTER TABLE inventory_items
  ADD CONSTRAINT ck_inventory_available_qty CHECK (available_quantity BETWEEN 0 AND capacity),
  ADD CONSTRAINT ck_inventory_type          CHECK (type IN ('seat','standing')),
  ADD CONSTRAINT ck_inventory_price         CHECK (price_amount >= 0),
  ADD CONSTRAINT ck_inventory_seat_capacity CHECK (type <> 'seat' OR capacity = 1);

-- Closes the NULL-distinctness hole: with both seat_id and standing_zone_id NULL
-- the two UNIQUE keys on this table stop working, because MySQL treats NULLs as
-- distinct. `type` must say which one is populated.
ALTER TABLE inventory_items
  ADD CONSTRAINT ck_inventory_target CHECK (
       (type = 'seat'     AND seat_id IS NOT NULL     AND standing_zone_id IS NULL)
    OR (type = 'standing' AND standing_zone_id IS NOT NULL AND seat_id IS NULL)
  );

-- ============================================================ hall model
ALTER TABLE sectors
  ADD CONSTRAINT ck_sectors_type CHECK (type IN ('seated','standing','mixed'));

ALTER TABLE hall_schema_versions
  ADD CONSTRAINT ck_schema_status CHECK (status IN ('draft','published','archived'));

-- §25 enumerates the session lifecycle explicitly, so it is constrained here.
-- Note the deliberate spelling split that runs through the whole schema:
-- orders/sessions/events use `cancelled` (two L), payments use `canceled` (one L).
ALTER TABLE sessions
  ADD CONSTRAINT ck_sessions_status CHECK (status IN (
    'draft','scheduled','on_sale','sold_out','closed','completed','cancelled'));

ALTER TABLE seats
  ADD CONSTRAINT ck_seats_type   CHECK (type IN ('standard','vip','wheelchair','companion','custom')),
  ADD CONSTRAINT ck_seats_status CHECK (status IN ('active','blocked','disabled'));

ALTER TABLE standing_zones
  ADD CONSTRAINT ck_standing_capacity CHECK (capacity > 0);

-- ============================================================ holds
ALTER TABLE seat_holds
  ADD CONSTRAINT ck_holds_quantity CHECK (quantity > 0);

-- ============================================================ sales
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

-- ============================================================ payments
ALTER TABLE payments
  ADD CONSTRAINT ck_payments_status CHECK (status IN (
    'pending','waiting_for_capture','succeeded','canceled','failed')),
  ADD CONSTRAINT ck_payments_amount CHECK (amount >= 0);

ALTER TABLE refunds
  ADD CONSTRAINT ck_refunds_status CHECK (status IN ('requested','processing','succeeded','failed')),
  ADD CONSTRAINT ck_refunds_amount CHECK (amount >= 0);

-- ============================================================ tickets
ALTER TABLE tickets
  ADD CONSTRAINT ck_tickets_status CHECK (status IN ('issued','used','cancelled','refunded','expired')),
  ADD CONSTRAINT ck_tickets_index  CHECK (ticket_index >= 1),
  -- state-diagrams.md: `used` is terminal. A ticket cannot be simultaneously
  -- admitted and cancelled/refunded — that is either a double-spend or a
  -- refund-after-entry, and both need a human workflow, not a silent row.
  ADD CONSTRAINT ck_tickets_terminal_exclusive CHECK (
    NOT (used_at IS NOT NULL AND (cancelled_at IS NOT NULL OR refunded_at IS NOT NULL))
  );

-- ============================================================ schema immutability
-- §20 and §98 both state that a published hall schema version is immutable, and
-- the baseline repeats it in a comment. Deletion is already blocked by the
-- `sessions` FK (ON DELETE RESTRICT) — mutation was blocked by nothing.
--
-- This is the highest-consequence of the application-level gaps: one careless
-- save() rewrites the seat geometry that already-sold sessions depend on, and
-- nothing anywhere reports a problem. CHECK constraints cannot reference OLD
-- values, so this needs a trigger.
DELIMITER //
DROP TRIGGER IF EXISTS trg_schema_version_immutable //
CREATE TRIGGER trg_schema_version_immutable
BEFORE UPDATE ON hall_schema_versions
FOR EACH ROW
BEGIN
  -- Any version that has left `draft` is frozen. Covering `archived` as well as
  -- `published` matters: archiving happens after the event, and without it the
  -- geometry of a completed, already-attended event could still be rewritten.
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

-- Deliberately NOT blocked: changing a published version's `status` (publishing
-- and archiving are normal lifecycle moves), and `updated_at` bookkeeping.
