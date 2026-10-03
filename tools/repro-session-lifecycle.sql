-- Reproduction for REVIEW-spec-bundle.md §3.18 — the session lifecycle.
--
--   docker exec -i nabilet-mysql mysql -uroot -prootpass \
--     -e "DROP DATABASE IF EXISTS nabilet_p8; CREATE DATABASE nabilet_p8;"
--   docker exec -i nabilet-mysql mysql -uroot -prootpass nabilet_p8 < migrations.sql
--   docker exec -i nabilet-mysql mysql -uroot -prootpass nabilet_p8 --table \
--     < tools/repro-session-lifecycle.sql
--
-- `probe()` runs one statement and reports ACCEPTED or REJECTED. Here ACCEPTED is
-- the finding. Two probes deliberately fail: the UPDATE against a column that does
-- not exist, and the count that proves it.

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

INSERT INTO users (id, public_id, email, password, created_at, updated_at)
VALUES (1, 'usr_aaaaaaaaaaaaaaaaaaaaaa', 'a@example.com', 'hash', @now, @now);

-- ── 1. a session that never expires ────────────────────────────────────────
CALL probe('1. a session with expires_at = NULL (the column is nullable):',
  "INSERT INTO user_sessions (id, user_id, session_token_hash, created_at)
   VALUES (1, 1, REPEAT('a', 64), NOW(6))");

-- ── 2. expires before it was created ───────────────────────────────────────
CALL probe('2. a session that expired an hour before it was created:',
  "INSERT INTO user_sessions (id, user_id, session_token_hash, expires_at, created_at)
   VALUES (2, 1, REPEAT('b', 64), DATE_SUB(NOW(6), INTERVAL 1 HOUR), NOW(6))");

-- ── 3. used after it expired ───────────────────────────────────────────────
CALL probe('3. last_seen_at three days after expires_at:',
  "INSERT INTO user_sessions (id, user_id, session_token_hash, expires_at, last_seen_at, created_at)
   VALUES (3, 1, REPEAT('c', 64), DATE_SUB(NOW(6), INTERVAL 3 DAY), NOW(6), DATE_SUB(NOW(6), INTERVAL 4 DAY))");

-- ── 4. there is no way to record a revocation ──────────────────────────────
SELECT COUNT(*) AS columns_that_can_record_a_revocation
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'user_sessions'
  AND (column_name LIKE '%revok%' OR column_name LIKE '%logout%' OR column_name LIKE '%ended%'
       OR column_name LIKE '%terminat%');

CALL probe('4. try to record a revocation:',
  "UPDATE user_sessions SET revoked_at = NOW(6) WHERE id = 1");

SELECT 'the only way to end one device is DELETE; the row disappears and nothing
        records that the session ever existed' AS consequence;

CALL probe('5. so revocation is a DELETE:', "DELETE FROM user_sessions WHERE id = 1");

-- ── 6. what is left behind ─────────────────────────────────────────────────
SELECT id, user_id, expires_at, last_seen_at, created_at,
       CASE WHEN expires_at IS NULL THEN 'immortal' ELSE 'ok' END AS verdict
FROM user_sessions ORDER BY id;
