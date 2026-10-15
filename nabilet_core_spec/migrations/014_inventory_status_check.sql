SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================ 014 — inventory_items.status dictionary
-- Mirrors the applied Laravel migration:
--   database/migrations/2026_10_12_000200_add_inventory_items_status_check.php
--
-- WHY THIS FILE EXISTS
--   `inventory_items.status` had no CHECK, and `InventoryItemStateMachine` — the
--   class the schema is supposed to be pinned against — did not list every value
--   the application writes. The two disagreed, and nothing could notice.
--
--   `CartItemService` writes `sold_out` whenever the position's capacity is
--   exhausted, and for a SEAT that is the ordinary case, not an edge case: the
--   seat has `capacity = 1`, so the first add-to-cart drops `available_quantity`
--   to zero and the status becomes `sold_out` (`held` is chosen only while free
--   capacity remains, i.e. for standing zones). `OrderService` says the same in
--   so many words: "до оплаты status может быть held/sold_out".
--
--   Because the machine had no `sold_out` state, `can('sold_out', 'sold')`
--   returned false, and `PaymentService::markInventorySoldForOrder()` — which
--   guards the write with that call (audit R-12) — logged a warning and skipped
--   the row. A PAID position therefore stayed `sold_out` forever:
--
--     * it never appeared in anything that reports on `sold`;
--     * `OrderService` resets `sold_out` to `available` on cancellation exactly
--       as it does for `held`, so the "sold" marker that is supposed to protect a
--       paid unit was simply never set.
--
--   Reproduced before this change and pinned afterwards by
--   `tests/Feature/Inventory/InventorySoldOnPaymentTest.php`, which buys through
--   the real path (cart → checkout → payment). The existing coverage missed it
--   because `PaymentInitiationGuardsTest::pendingOrder()` creates the order
--   directly through `OrderService`, bypassing the cart, which leaves the
--   position in `available` — and `available → sold` was allowed all along.
--
--   Code enforcement landed in the same change: `sold_out` is now a state of
--   `InventoryItemStateMachine` with the transitions the code actually performs.
--
-- WHY THE VALUES ARE NOT INVENTED
--   The six values are the intersection of what the writers already store
--   (`CartItemService`, `OrderService`, `PaymentService`) and what the storefront
--   already expects — `resources/js/lib/seatStatus.ts` documents the backend
--   vocabulary as `available | held | sold_out | sold | blocked | disabled`.
--   This file only makes the database enforce an agreement the rest of the stack
--   had already made — AD-12: if the database can reject it, the database should.
--
--   `blocked` and `disabled` have no writer anywhere in the application yet;
--   they exist for the admin surface. They are included because removing them
--   from the constraint would mean deleting declared states, which is a product
--   decision, not a cleanup.
--
-- WHY A NEW FILE INSTEAD OF AN EDIT TO 009
--   `009_integrity_hardening.sql` — and its Laravel counterpart
--   `2026_09_20_001000_add_check_constraints.php` — have already run on every
--   deployed database. A constraint added there would exist in the bundle and
--   nowhere else. A new split plus a new migration reaches existing and fresh
--   installs alike, which is exactly why `011_checkout_fixes.sql` and
--   `013_users_status_check.sql` exist.

ALTER TABLE inventory_items
  ADD CONSTRAINT ck_inventory_status CHECK (status IN ('available','held','sold_out','sold','blocked','disabled'));

SET FOREIGN_KEY_CHECKS = 1;
