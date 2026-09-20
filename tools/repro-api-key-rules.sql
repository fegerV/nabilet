-- Reproduction for REVIEW-spec-bundle.md §3.19 — API keys and IP rules.
--
--   docker exec -i nabilet-mysql mysql -uroot -prootpass \
--     -e "DROP DATABASE IF EXISTS nabilet_p9; CREATE DATABASE nabilet_p9;"
--   docker exec -i nabilet-mysql mysql -uroot -prootpass nabilet_p9 < migrations.sql
--   docker exec -i nabilet-mysql mysql -uroot -prootpass nabilet_p9 --table \
--     < tools/repro-api-key-rules.sql
--
-- `probe()` runs one statement and reports ACCEPTED or REJECTED. Here ACCEPTED is
-- the finding.

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

INSERT INTO organizations (id, public_id, name, slug, created_at, updated_at)
VALUES (1, 'org_aaaaaaaaaaaaaaaaaaaaaa', 'Org', 'org', @now, @now);

-- ── api_keys ───────────────────────────────────────────────────────────────

CALL probe('1. an API key that belongs to no organization:',
  "INSERT INTO api_keys (id, public_id, organization_id, name, key_prefix, key_hash, created_at)
   VALUES (1, 'apk_aaaaaaaaaaaaaaaaaaaaaa', NULL, 'orphan', 'nb_live_', REPEAT('a', 64), NOW(6))");

CALL probe('2. an API key that never expires:',
  "INSERT INTO api_keys (id, public_id, organization_id, name, key_prefix, key_hash, created_at)
   VALUES (2, 'apk_bbbbbbbbbbbbbbbbbbbbbb', 1, 'forever', 'nb_live_', REPEAT('b', 64), NOW(6))");

CALL probe('3. an API key with no scopes at all:',
  "INSERT INTO api_keys (id, public_id, organization_id, name, key_prefix, key_hash, scopes_json, created_at)
   VALUES (3, 'apk_cccccccccccccccccccccc', 1, 'noscope', 'nb_live_', REPEAT('c', 64), NULL, NOW(6))");

CALL probe('4. a revocation dated in the future:',
  "INSERT INTO api_keys (id, public_id, organization_id, name, key_prefix, key_hash, revoked_at, created_at)
   VALUES (4, 'apk_dddddddddddddddddddddd', 1, 'scheduled', 'nb_live_', REPEAT('d', 64),
           DATE_ADD(NOW(6), INTERVAL 1 YEAR), NOW(6))");

CALL probe('5. un-revoke a key (revoked_at back to NULL):',
  "UPDATE api_keys SET revoked_at = DATE_SUB(NOW(6), INTERVAL 1 DAY) WHERE id = 2");
CALL probe('   ... and then silently undo it:',
  "UPDATE api_keys SET revoked_at = NULL WHERE id = 2");

CALL probe('6. extend the expiry of a revoked key:',
  "UPDATE api_keys SET expires_at = DATE_ADD(NOW(6), INTERVAL 10 YEAR) WHERE id = 4");

-- Nothing records that a key was ever used.
SELECT COUNT(*) AS columns_that_record_last_use
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'api_keys'
  AND (column_name LIKE '%last_used%' OR column_name LIKE '%used_at%' OR column_name LIKE '%last_seen%');

-- ── ip_rules ───────────────────────────────────────────────────────────────

CALL probe('7. a rule with neither an IP nor a CIDR:',
  "INSERT INTO ip_rules (id, rule_type, scope, created_at)
   VALUES (1, 'deny', 'global', NOW(6))");
SELECT 'a deny rule with no target matches nothing — or everything, depending on
        which side of the code evaluates an empty rule' AS consequence;

CALL probe('8. a rule with BOTH an IP and a CIDR:',
  "INSERT INTO ip_rules (id, ip_address, cidr, rule_type, scope, created_at)
   VALUES (2, INET6_ATON('10.0.0.1'), '192.168.0.0/24', 'deny', 'global', NOW(6))");

CALL probe("9. rule_type = 'banana':",
  "INSERT INTO ip_rules (id, rule_type, scope, created_at)
   VALUES (3, 'banana', 'global', NOW(6))");

CALL probe('10. a rule that expired last year and is still active:',
  "INSERT INTO ip_rules (id, ip_address, rule_type, scope, active, expires_at, created_at)
   VALUES (4, INET6_ATON('10.0.0.2'), 'deny', 'global', 1,
           DATE_SUB(NOW(6), INTERVAL 1 YEAR), NOW(6))");

CALL probe("11. two contradicting rules for the same address:",
  "INSERT INTO ip_rules (id, ip_address, rule_type, scope, created_at)
   VALUES (5, INET6_ATON('10.0.0.2'), 'allow', 'global', NOW(6))");

SELECT id, rule_type, active, expires_at,
       CASE WHEN ip_address IS NULL AND cidr IS NULL THEN 'no target'
            WHEN ip_address IS NOT NULL AND cidr IS NOT NULL THEN 'two targets'
            ELSE 'ok' END AS target
FROM ip_rules ORDER BY id;
