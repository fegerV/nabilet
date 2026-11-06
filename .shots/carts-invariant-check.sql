-- Functional proof of the corrected carts uniqueness (spec side).
-- Scenario: the original uq_carts_token_session_status raised
--   1062 Duplicate entry '<token>-<session>-converted'
-- on the SECOND purchase in the same session — a raw 500 on a normal action.
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM carts;
DELETE FROM sessions;
DELETE FROM hall_schema_versions;
DELETE FROM halls;
DELETE FROM venues;
DELETE FROM events;
DELETE FROM organizations;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO organizations (id, public_id, name, slug, created_at, updated_at)
VALUES (1, '01AAAAAAAAAAAAAAAAAAAAAAAA', 'Test Org', 'test-org', NOW(6), NOW(6));

INSERT INTO venues (id, public_id, organization_id, name, slug, created_at, updated_at)
VALUES (1, '01BBBBBBBBBBBBBBBBBBBBBBBB', 1, 'Venue', 'venue', NOW(6), NOW(6));

INSERT INTO halls (id, public_id, venue_id, name, created_at, updated_at)
VALUES (1, '01CCCCCCCCCCCCCCCCCCCCCCCC', 1, 'Hall', NOW(6), NOW(6));

INSERT INTO hall_schema_versions (id, public_id, hall_id, version, schema_json, created_at, updated_at)
VALUES (1, '01DDDDDDDDDDDDDDDDDDDDDDDD', 1, 1, '{}', NOW(6), NOW(6));

INSERT INTO events (id, public_id, organization_id, title, slug, created_at, updated_at)
VALUES (1, '01EEEEEEEEEEEEEEEEEEEEEEEE', 1, 'Event', 'event', NOW(6), NOW(6));

INSERT INTO sessions (id, public_id, event_id, venue_id, hall_id, schema_version_id, starts_at, created_at, updated_at)
VALUES (1, '01FFFFFFFFFFFFFFFFFFFFFFFF', 1, 1, 1, 1, NOW(6), NOW(6), NOW(6));
