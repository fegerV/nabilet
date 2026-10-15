SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================ 015 — events.status dictionary
-- Mirrors the applied Laravel migration:
--   database/migrations/2026_10_12_000300_add_events_status_check.php
--
-- WHY THIS FILE EXISTS
--   `events.status` was the last column backed by a PHP state machine with no
--   CHECK in the schema. `tools/verify-state-machines.php` reported it on every
--   run — "no CHECK on status — machine is unenforced" — and a note that repeats
--   on every run is a note nobody reads.
--
--   The difference from `inventory_items` (014) is that here the CODE was already
--   disciplined and only the database was silent. `EventStateMachine` and
--   `EventStatus` agree on the same six values, and every writer goes through
--   them: `EventService::publish()/archive()/cancel()/schedule()/complete()` use
--   `EventStatus` constants, `EventPublicationService` uses `Event::PUBLISHED`
--   and one literal `'cancelled'`. The column default is `'draft'`.
--
--   What made the silence dangerous rather than theoretical: `status` is
--   DELIBERATELY absent from `StoreEventRequest` and `UpdateEventRequest` (a
--   `PUT` with `{"status":"published"}` used to publish an event without
--   sessions, bypassing `EventPublicationPolicy`). With the field gone from the
--   rules, the guard against that is now "no rule, so `validated()` drops it" —
--   a guard that lives in one framework detail. The CHECK is the second lock:
--   even if a future request class re-introduces the field, the database will not
--   accept a status the machine does not know.
--
--   `EventStateMachine` already documents this gap in its own docblock — the
--   `cancelled` spelling (two L, matching orders/sessions/tickets) was pinned "by
--   convention (and by tests) rather than by the database". It no longer has to be.
--
-- WHY THE VALUES ARE NOT INVENTED
--   The six values are `EventStatus::all()` — the same list `EventStateMachine`
--   transitions over, the same list the publication policy reasons about, and the
--   same list the admin badge renders. Nothing is added here that the code did
--   not already treat as valid.
--
-- WHY A NEW FILE INSTEAD OF AN EDIT TO 009
--   `009_integrity_hardening.sql` — and its Laravel counterpart
--   `2026_09_20_001000_add_check_constraints.php` — have already run on every
--   deployed database. A constraint added there would exist in the bundle and
--   nowhere else. A new split plus a new migration reaches existing and fresh
--   installs alike, which is exactly why `011`, `013` and `014` exist.

ALTER TABLE events
  ADD CONSTRAINT ck_events_status CHECK (status IN ('draft','scheduled','published','completed','cancelled','archived'));

SET FOREIGN_KEY_CHECKS = 1;
