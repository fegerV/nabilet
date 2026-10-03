-- Reproduction for REVIEW-spec-bundle.md §3.17 — the identity lifecycle.
--
--   docker exec -i nabilet-mysql mysql -uroot -prootpass \
--     -e "DROP DATABASE IF EXISTS nabilet_p7; CREATE DATABASE nabilet_p7;"
--   docker exec -i nabilet-mysql mysql -uroot -prootpass nabilet_p7 < migrations.sql
--   docker exec -i nabilet-mysql mysql -uroot -prootpass nabilet_p7 --table \
--     < tools/repro-user-identity.sql
--
-- `probe()` runs one statement and reports ACCEPTED or REJECTED. Here ACCEPTED is
-- the finding: every statement below is something the schema should have stopped.

DELIMITER $$
DROP PROCEDURE IF EXISTS probe$$
CREATE PROCEDURE probe(IN label VARCHAR(120), IN stmt TEXT)
BEGIN
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 @ecode = MYSQL_ERRNO, @emsg = MESSAGE_TEXT;
    SELECT label AS probe, CONCAT('REJECTED (', @ecode, ') ', LEFT(@emsg, 90)) AS result;
  END;

  SET @s = stmt;
  PREPARE p FROM @s;
  EXECUTE p;
  DEALLOCATE PREPARE p;
  SELECT label AS probe, 'ACCEPTED — that is the finding' AS result;
END$$
DELIMITER ;

SET @now = NOW(6);

-- A normal, verified user.
INSERT INTO users (id, public_id, email, email_verified_at, password, phone, phone_verified_at, created_at, updated_at)
VALUES (1, 'usr_aaaaaaaaaaaaaaaaaaaaaa', 'a@example.com', @now, 'hash', '+79000000000', @now, @now, @now);

-- ── 1. a deleted account keeps its email forever ───────────────────────────
CALL probe(
  '1. soft-delete the user, then register again with the same email:',
  CONCAT("UPDATE users SET deleted_at = '", @now, "' WHERE id = 1")
);
CALL probe(
  '   ... and now INSERT a new user with that same email:',
  "INSERT INTO users (id, public_id, email, password, created_at, updated_at)
   VALUES (2, 'usr_bbbbbbbbbbbbbbbbbbbbbb', 'a@example.com', 'hash', NOW(6), NOW(6))"
);
SELECT 'uq_users_email does not include deleted_at: a deleted account pins its address'
  AS consequence;

-- ── 2. change the address, keep the verification ───────────────────────────
CALL probe(
  '2. move a verified user to an address nobody confirmed:',
  "UPDATE users SET deleted_at = NULL, email = 'attacker@example.com' WHERE id = 1"
);
SELECT u.id, u.email, u.email_verified_at,
       CASE WHEN u.email_verified_at IS NOT NULL
            THEN 'still "verified" — for an address that was never confirmed'
            ELSE 'ok' END AS verdict
FROM users u WHERE u.id = 1;

-- ── 3. verified, with no address at all ────────────────────────────────────
CALL probe('3. clear the email but keep email_verified_at:',
  "UPDATE users SET email = NULL WHERE id = 1");
SELECT u.id, u.email, u.email_verified_at,
       CASE WHEN u.email IS NULL AND u.email_verified_at IS NOT NULL
            THEN 'verified nothing' ELSE 'ok' END AS verdict
FROM users u WHERE u.id = 1;

CALL probe('4. same for the phone:',
  "UPDATE users SET phone = '+79111111111' WHERE id = 1");
SELECT u.id, u.phone, u.phone_verified_at,
       CASE WHEN u.phone_verified_at IS NOT NULL
            THEN 'phone "verified" for a number that was never confirmed' ELSE 'ok' END AS verdict
FROM users u WHERE u.id = 1;

-- ── 5. an account nobody can reach ─────────────────────────────────────────
CALL probe('5. a user with no email, no phone and no password:',
  "INSERT INTO users (id, public_id, created_at, updated_at)
   VALUES (3, 'usr_cccccccccccccccccccccc', NOW(6), NOW(6))");
SELECT 'no OAuth/social table exists in the bundle, so such an account cannot log in,
        cannot be sent a ticket and cannot reset a password' AS consequence;

-- ── 6. status ──────────────────────────────────────────────────────────────
CALL probe("6. status = 'banana':", "UPDATE users SET status = 'banana' WHERE id = 1");

-- ── 7. the remedy for probe 1, executed ────────────────────────────────────
-- Anonymising IN PLACE releases the address (NULL does not collide). Hard DELETE
-- would release it too, but the FK from orders/tickets forbids it; soft DELETE
-- keeps the personal data AND pins the address. So: null the column, keep the row.
CALL probe('7. anonymise the tombstone in place (email = NULL):',
  "UPDATE users SET email = NULL, phone = NULL, first_name = NULL, last_name = NULL,
          password = NULL, email_verified_at = NULL, phone_verified_at = NULL WHERE id = 1");
CALL probe('   ... and now the address is free again:',
  "INSERT INTO users (id, public_id, email, password, created_at, updated_at)
   VALUES (2, 'usr_bbbbbbbbbbbbbbbbbbbbbb', 'a@example.com', 'hash', NOW(6), NOW(6))");

-- ── 8. the column collation decides identity, not PHP ──────────────────────
CALL probe('8. register the same address in a different case:',
  "INSERT INTO users (id, public_id, email, password, created_at, updated_at)
   VALUES (4, 'usr_dddddddddddddddddddddd', 'A@EXAMPLE.COM', 'hash', NOW(6), NOW(6))");
SELECT 'utf8mb4_unicode_ci is case-insensitive: PHP "===" says these differ, MySQL
        says they are the same person' AS consequence;

CALL probe('9. same address with a trailing space:',
  "INSERT INTO users (id, public_id, email, password, created_at, updated_at)
   VALUES (5, 'usr_eeeeeeeeeeeeeeeeeeeeee', 'a@example.com ', 'hash', NOW(6), NOW(6))");

DELETE FROM users WHERE id IN (2, 4, 5);
