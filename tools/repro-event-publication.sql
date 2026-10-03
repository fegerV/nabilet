-- Reproduction for REVIEW-spec-bundle.md §3.20 — event publication and translations.
--
--   docker exec -i nabilet-mysql mysql -uroot -prootpass \
--     -e "DROP DATABASE IF EXISTS nabilet_p10; CREATE DATABASE nabilet_p10;"
--   docker exec -i nabilet-mysql mysql -uroot -prootpass nabilet_p10 < migrations.sql
--   docker exec -i nabilet-mysql mysql -uroot -prootpass nabilet_p10 --table \
--     < tools/repro-event-publication.sql
--
-- `probe()` runs one statement and reports ACCEPTED or REJECTED. Here ACCEPTED is
-- the finding; the two 1062s are the mechanism by which an empty translation
-- destroys the real one.

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

INSERT INTO users (id, public_id, email, created_at, updated_at)
VALUES (1, 'usr_aaaaaaaaaaaaaaaaaaaaaa', 'a@example.com', @now, @now);

-- ── 1. published, with no moment of publication ────────────────────────────
CALL probe("1. status = 'published' with published_at = NULL:",
  "INSERT INTO events (id, public_id, organization_id, title, slug, status, published_at, created_at, updated_at)
   VALUES (1, 'evt_aaaaaaaaaaaaaaaaaaaaaa', 1, 'Concert', 'concert', 'published', NULL, NOW(6), NOW(6))");

-- ── 2. published at a moment in the future ─────────────────────────────────
CALL probe('2. published with published_at a year ahead:',
  "INSERT INTO events (id, public_id, organization_id, title, slug, status, published_at, created_at, updated_at)
   VALUES (2, 'evt_bbbbbbbbbbbbbbbbbbbbbb', 1, 'Soon', 'soon', 'published',
           DATE_ADD(NOW(6), INTERVAL 1 YEAR), NOW(6), NOW(6))");

-- ── 3. published with nothing to sell ──────────────────────────────────────
SELECT (SELECT COUNT(*) FROM sessions WHERE event_id = 1) AS sessions_of_event_1,
       'a published event with zero sessions: no date, no price, no buy button'
         AS consequence;

-- ── 4. cancelled without consulting anything ───────────────────────────────
CALL probe("4. status = 'cancelled' (the row has no column that could object):",
  "UPDATE events SET status = 'cancelled' WHERE id = 1");

-- ── 5. status vocabulary ───────────────────────────────────────────────────
CALL probe("5. status = 'banana':", "UPDATE events SET status = 'banana' WHERE id = 2");

-- ── 6. a translation that translates nothing ───────────────────────────────
CALL probe('6. a translation row with every content column NULL:',
  "INSERT INTO event_translations (event_id, locale) VALUES (1, 'en')");

CALL probe('   ... and now the real English translation cannot be written:',
  "INSERT INTO event_translations (event_id, locale, title, short_description)
   VALUES (1, 'en', 'Concert', 'A concert')");

SELECT 'uq_event_translations (event_id, locale) makes the empty row a lock: the
        locale can never be filled in until somebody deletes the placeholder'
  AS consequence;

-- ── 7. SEO for an entity that does not exist ───────────────────────────────
CALL probe('7. seo_meta for entity_id 999999 (no foreign key on a polymorphic pair):',
  "INSERT INTO seo_meta (entity_type, entity_id, locale, title) VALUES ('event', 999999, 'ru', 'Ghost')");

-- ── 8. a redirect with a nonsense status code ──────────────────────────────
CALL probe('8. redirects with status_code = 999:',
  "INSERT INTO redirects (source, destination, status_code, created_at, updated_at)
   VALUES ('/old', '/new', 999, NOW(6), NOW(6))");

-- ── 9. two different long URLs that share a 512-character prefix ───────────
CALL probe('9. two distinct URLs longer than the 512-char prefix index:',
  "INSERT INTO redirects (source, destination, created_at, updated_at)
   VALUES (CONCAT(REPEAT('a', 600), '-one'), '/one', NOW(6), NOW(6))");
CALL probe('   ... the second one:',
  "INSERT INTO redirects (source, destination, created_at, updated_at)
   VALUES (CONCAT(REPEAT('a', 600), '-two'), '/two', NOW(6), NOW(6))");
SELECT 'uq_redirect_source is (source(512)): URLs are compared on their first 512
        characters only, so distinct long URLs collide' AS consequence;

SELECT id, status, published_at,
       CASE WHEN status = 'published' AND published_at IS NULL THEN 'published, no moment'
            WHEN status = 'published' AND published_at > NOW(6) THEN 'published in the future'
            ELSE 'ok' END AS verdict
FROM events ORDER BY id;
