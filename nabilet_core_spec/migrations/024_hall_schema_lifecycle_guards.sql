-- ============================================================ 024 — hall schema lifecycle guards
-- Замена единственного триггера 009 (`trg_schema_version_immutable`) на тройку
-- справедливых стражей жизненного цикла. Источник — миграция
-- 2026_10_06_000100_harden_hall_schema_lifecycle.php: сначала ставится строгий
-- страж под новым именем, затем снимается устаревший, поэтому замена не
-- оставляет окна без защиты.
--
--   trg_schema_version_lifecycle_guard  BEFORE UPDATE — идентичность версии
--     (id/public_id/hall_id/version/created_at) неизменяема всегда; поля
--     опубликованной или archived версии заморожены; переходы статусов
--     ограничены машиной (published→archived, draft→published, черновик
--     обязан наращивать revision при правке контента);
--   trg_schema_version_delete_guard     BEFORE DELETE — published/archived
--     версии не удаляются;
--   trg_hall_frozen_schema_delete_guard BEFORE DELETE ON halls — зал с
--     замороженными версиями не удаляется (MySQL не вызывает триггеры
--     дочерней таблицы при каскаде FK, поэтому родительский путь закрыт
--     отдельно).
DELIMITER //
DROP TRIGGER IF EXISTS trg_schema_version_immutable //
CREATE TRIGGER trg_schema_version_lifecycle_guard
BEFORE UPDATE ON hall_schema_versions
FOR EACH ROW
BEGIN
  IF NOT (NEW.id <=> OLD.id)
     OR NOT (NEW.public_id <=> OLD.public_id)
     OR NOT (NEW.hall_id <=> OLD.hall_id)
     OR NOT (NEW.version <=> OLD.version)
     OR NOT (NEW.created_at <=> OLD.created_at) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hall schema identity is immutable';
  END IF;
  IF OLD.status IN ('published', 'archived') THEN
    IF NOT (NEW.id <=> OLD.id)
       OR NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.hall_id <=> OLD.hall_id)
       OR NOT (NEW.version <=> OLD.version)
       OR NOT (NEW.width <=> OLD.width)
       OR NOT (NEW.height <=> OLD.height)
       OR NOT (NEW.background_url <=> OLD.background_url)
       OR NOT (NEW.schema_json <=> OLD.schema_json)
       OR NOT (NEW.published_at <=> OLD.published_at)
       OR NOT (NEW.created_at <=> OLD.created_at)
       OR NOT (NEW.revision <=> OLD.revision)
    THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published hall schema data is immutable';
    END IF;
    IF OLD.status = 'published' AND NEW.status NOT IN ('published', 'archived') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid published hall schema transition';
    END IF;
    IF OLD.status = 'archived' AND NEW.status <> 'archived' THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Archived hall schema cannot be restored';
    END IF;
  ELSEIF OLD.status = 'draft' THEN
    IF NEW.status NOT IN ('draft', 'published') THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid draft hall schema transition';
    END IF;
    IF NEW.status = 'draft' AND NEW.published_at IS NOT NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Draft hall schema cannot have published_at';
    END IF;
    IF NEW.status = 'published' AND NEW.published_at IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published hall schema requires published_at';
    END IF;
    IF NEW.status = 'published' AND (
         NOT (NEW.public_id <=> OLD.public_id)
         OR NOT (NEW.hall_id <=> OLD.hall_id)
         OR NOT (NEW.version <=> OLD.version)
         OR NOT (NEW.width <=> OLD.width)
         OR NOT (NEW.height <=> OLD.height)
         OR NOT (NEW.background_url <=> OLD.background_url)
         OR NOT (NEW.schema_json <=> OLD.schema_json)
         OR NOT (NEW.revision <=> OLD.revision)
       ) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Save draft content before publishing';
    END IF;
    IF NEW.status = 'draft' AND (
         NOT (NEW.public_id <=> OLD.public_id)
         OR NOT (NEW.hall_id <=> OLD.hall_id)
         OR NOT (NEW.version <=> OLD.version)
         OR NOT (NEW.width <=> OLD.width)
         OR NOT (NEW.height <=> OLD.height)
         OR NOT (NEW.background_url <=> OLD.background_url)
         OR NOT (NEW.schema_json <=> OLD.schema_json)
       ) AND NOT (NEW.revision <=> COALESCE(OLD.revision, 1) + 1) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Draft content changes must increment revision';
    END IF;
    IF NOT (NEW.revision <=> OLD.revision)
       AND NOT (NEW.revision <=> COALESCE(OLD.revision, 1) + 1) THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid hall schema revision';
    END IF;
  END IF;
END //
DROP TRIGGER IF EXISTS trg_schema_version_delete_guard //
CREATE TRIGGER trg_schema_version_delete_guard
BEFORE DELETE ON hall_schema_versions
FOR EACH ROW
BEGIN
  IF OLD.status IN ('published', 'archived') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published hall schema versions cannot be deleted';
  END IF;
END //
DROP TRIGGER IF EXISTS trg_hall_frozen_schema_delete_guard //
CREATE TRIGGER trg_hall_frozen_schema_delete_guard
BEFORE DELETE ON halls
FOR EACH ROW
BEGIN
  IF EXISTS (
    SELECT 1 FROM hall_schema_versions
    WHERE hall_id = OLD.id AND status IN ('published', 'archived')
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hall with frozen schema versions cannot be deleted';
  END IF;
END //
DELIMITER ;

