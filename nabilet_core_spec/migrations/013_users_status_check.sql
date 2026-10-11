SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================ 013 — users.status dictionary
-- Mirrors the applied Laravel migration:
--   database/migrations/2026_10_12_000100_add_users_status_check.php
--
-- WHY THIS FILE EXISTS
--   `users.status` was the only status column in the schema with neither a CHECK
--   nor a vocabulary written down anywhere except one validation rule inside
--   `UserController::update()` — the literal string `in:active,inactive,banned`.
--   Two consequences, both confirmed before this change:
--
--     1. A ban did not stop anyone from logging in. `UserService::authenticate()`
--        compared the password hash and nothing else, so `status = 'banned'`
--        revoked the existing sessions and the same password immediately bought a
--        fresh one. Revoking sessions without refusing the login is a logout, not
--        a loss of access.
--     2. Anything writing the column outside that one endpoint — a seeder, a
--        script, a direct SQL fix — could store an arbitrary string, and the
--        database accepted it silently.
--
--   The vocabulary is NOT invented here. It is the set the API already accepted
--   (`UserController`), the admin UI already renders (badge: Активен / Неактивен
--   / Заблокирован) and `UserSessionRevocationTest` already treats as access
--   removal (`banned` and `inactive` both revoke tokens). This file only makes
--   the database enforce an agreement the rest of the stack had already made —
--   AD-12: if the database can reject it, the database should.
--
--   Code enforcement landed in the same change: the dictionary is now
--   `Nabilet\Modules\Core\Users\Domain\UserStatus`, `UserService::authenticate()`
--   refuses every status other than `active`, and an unrecognised value is
--   treated as "no access" rather than "allow" (fail-closed). The rule and the
--   check read the same list, so they cannot drift apart the way the three
--   copies of the password policy did.
--
-- WHY A NEW FILE INSTEAD OF AN EDIT TO 009
--   `009_integrity_hardening.sql` — and its Laravel counterpart
--   `2026_09_20_001000_add_check_constraints.php` — have already run on every
--   deployed database. A constraint added there would exist in the bundle and
--   nowhere else. A new split plus a new migration reaches existing and fresh
--   installs alike, which is exactly why `011_checkout_fixes.sql` exists.

ALTER TABLE users
  ADD CONSTRAINT ck_users_status CHECK (status IN ('active','inactive','banned'));

SET FOREIGN_KEY_CHECKS = 1;
