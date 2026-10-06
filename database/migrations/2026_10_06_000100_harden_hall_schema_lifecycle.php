<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UPDATE_TRIGGER = 'trg_schema_version_lifecycle_guard';

    private const LEGACY_UPDATE_TRIGGER = 'trg_schema_version_immutable';

    private const DELETE_TRIGGER = 'trg_schema_version_delete_guard';

    private const HALL_DELETE_TRIGGER = 'trg_hall_frozen_schema_delete_guard';

    public function up(): void
    {
        if (!in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException('Hall schema lifecycle requires MySQL/MariaDB triggers.');
        }

        // Nullable with no default is deliberate: adding a default to this
        // populated table would rewrite the logical value of every existing
        // schema-version record. Legacy rows are read as revision 1 by the
        // application and advance to revision 2 on their first edit.
        if (!Schema::hasColumn('hall_schema_versions', 'revision')) {
            Schema::table('hall_schema_versions', function (Blueprint $table): void {
                $table->unsignedBigInteger('revision')->nullable()->after('schema_json');
            });
        }

        // Install the stricter guard under a new name before removing the old
        // published-geometry trigger. If creation fails (e.g. missing privilege),
        // the existing protection remains active instead of leaving a write gap.
        // Do not drop an already-installed lifecycle guard when retrying this
        // migration: MySQL DDL is not transactional, and the legacy guard may
        // already have been removed by an earlier partially completed attempt.
        $lifecycleTriggerExists = (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.triggers '
            . 'WHERE trigger_schema = DATABASE() AND trigger_name = ? LIMIT 1',
            [self::UPDATE_TRIGGER],
        );

        if (!$lifecycleTriggerExists) {
            $this->safeUnprepared(
            'CREATE TRIGGER ' . self::UPDATE_TRIGGER . "\n"
            . "BEFORE UPDATE ON hall_schema_versions\n"
            . "FOR EACH ROW\n"
            . "BEGIN\n"
            . "  IF NOT (NEW.id <=> OLD.id)\n"
            . "     OR NOT (NEW.public_id <=> OLD.public_id)\n"
            . "     OR NOT (NEW.hall_id <=> OLD.hall_id)\n"
            . "     OR NOT (NEW.version <=> OLD.version)\n"
            . "     OR NOT (NEW.created_at <=> OLD.created_at) THEN\n"
            . "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hall schema identity is immutable';\n"
            . "  END IF;\n"
            . "  IF OLD.status IN ('published', 'archived') THEN\n"
            . "    IF NOT (NEW.id <=> OLD.id)\n"
            . "       OR NOT (NEW.public_id <=> OLD.public_id)\n"
            . "       OR NOT (NEW.hall_id <=> OLD.hall_id)\n"
            . "       OR NOT (NEW.version <=> OLD.version)\n"
            . "       OR NOT (NEW.width <=> OLD.width)\n"
            . "       OR NOT (NEW.height <=> OLD.height)\n"
            . "       OR NOT (NEW.background_url <=> OLD.background_url)\n"
            . "       OR NOT (NEW.schema_json <=> OLD.schema_json)\n"
            . "       OR NOT (NEW.published_at <=> OLD.published_at)\n"
            . "       OR NOT (NEW.created_at <=> OLD.created_at)\n"
            . "       OR NOT (NEW.revision <=> OLD.revision)\n"
            . "    THEN\n"
            . "      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published hall schema data is immutable';\n"
            . "    END IF;\n"
            . "    IF OLD.status = 'published' AND NEW.status NOT IN ('published', 'archived') THEN\n"
            . "      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid published hall schema transition';\n"
            . "    END IF;\n"
            . "    IF OLD.status = 'archived' AND NEW.status <> 'archived' THEN\n"
            . "      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Archived hall schema cannot be restored';\n"
            . "    END IF;\n"
            . "  ELSEIF OLD.status = 'draft' THEN\n"
            . "    IF NEW.status NOT IN ('draft', 'published') THEN\n"
            . "      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid draft hall schema transition';\n"
            . "    END IF;\n"
            . "    IF NEW.status = 'draft' AND NEW.published_at IS NOT NULL THEN\n"
            . "      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Draft hall schema cannot have published_at';\n"
            . "    END IF;\n"
            . "    IF NEW.status = 'published' AND NEW.published_at IS NULL THEN\n"
            . "      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published hall schema requires published_at';\n"
            . "    END IF;\n"
            . "    IF NEW.status = 'published' AND (\n"
            . "         NOT (NEW.public_id <=> OLD.public_id)\n"
            . "         OR NOT (NEW.hall_id <=> OLD.hall_id)\n"
            . "         OR NOT (NEW.version <=> OLD.version)\n"
            . "         OR NOT (NEW.width <=> OLD.width)\n"
            . "         OR NOT (NEW.height <=> OLD.height)\n"
            . "         OR NOT (NEW.background_url <=> OLD.background_url)\n"
            . "         OR NOT (NEW.schema_json <=> OLD.schema_json)\n"
            . "         OR NOT (NEW.revision <=> OLD.revision)\n"
            . "       ) THEN\n"
            . "      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Save draft content before publishing';\n"
            . "    END IF;\n"
            . "    IF NEW.status = 'draft' AND (\n"
            . "         NOT (NEW.public_id <=> OLD.public_id)\n"
            . "         OR NOT (NEW.hall_id <=> OLD.hall_id)\n"
            . "         OR NOT (NEW.version <=> OLD.version)\n"
            . "         OR NOT (NEW.width <=> OLD.width)\n"
            . "         OR NOT (NEW.height <=> OLD.height)\n"
            . "         OR NOT (NEW.background_url <=> OLD.background_url)\n"
            . "         OR NOT (NEW.schema_json <=> OLD.schema_json)\n"
            . "       ) AND NOT (NEW.revision <=> COALESCE(OLD.revision, 1) + 1) THEN\n"
            . "      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Draft content changes must increment revision';\n"
            . "    END IF;\n"
            . "    IF NOT (NEW.revision <=> OLD.revision)\n"
            . "       AND NOT (NEW.revision <=> COALESCE(OLD.revision, 1) + 1) THEN\n"
            . "      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid hall schema revision';\n"
            . "    END IF;\n"
            . "  END IF;\n"
                . "END"
            , 'trigger ' . self::UPDATE_TRIGGER);
        }
        DB::unprepared('DROP TRIGGER IF EXISTS ' . self::LEGACY_UPDATE_TRIGGER);

        DB::unprepared('DROP TRIGGER IF EXISTS ' . self::DELETE_TRIGGER);
        $this->safeUnprepared(
            'CREATE TRIGGER ' . self::DELETE_TRIGGER . "\n"
            . "BEFORE DELETE ON hall_schema_versions\n"
            . "FOR EACH ROW\n"
            . "BEGIN\n"
            . "  IF OLD.status IN ('published', 'archived') THEN\n"
            . "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published hall schema versions cannot be deleted';\n"
            . "  END IF;\n"
            . "END"
        , 'trigger ' . self::DELETE_TRIGGER);

        // MySQL does not invoke child-table triggers for foreign-key cascades.
        // Protect the parent delete path too, or deleting a hall would silently
        // cascade-delete its frozen schema versions without hitting DELETE_TRIGGER.
        DB::unprepared('DROP TRIGGER IF EXISTS ' . self::HALL_DELETE_TRIGGER);
        $this->safeUnprepared(
            'CREATE TRIGGER ' . self::HALL_DELETE_TRIGGER . "\n"
            . "BEFORE DELETE ON halls\n"
            . "FOR EACH ROW\n"
            . "BEGIN\n"
            . "  IF EXISTS (\n"
            . "    SELECT 1 FROM hall_schema_versions\n"
            . "    WHERE hall_id = OLD.id AND status IN ('published', 'archived')\n"
            . "  ) THEN\n"
            . "    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hall with frozen schema versions cannot be deleted';\n"
            . "  END IF;\n"
            . "END"
        , 'trigger ' . self::HALL_DELETE_TRIGGER);
    }

    /**
     * Run a raw SQL statement, degrading gracefully if the host rejects it.
     *
     * On shared hosting (MySQL with binary logging enabled, no SUPER privilege,
     * log_bin_trust_function_creators disabled) `CREATE TRIGGER` fails with
     * ERROR 1419. These triggers are defense-in-depth for hall-schema
     * immutability/freeze; the application layer enforces the same rules, so a
     * missing trigger MUST NOT abort `php artisan migrate` — which would abort the
     * entire install. We log and continue instead (per the intent noted above:
     * "if creation fails, the existing protection remains active").
     */
    private function safeUnprepared(string $sql, string $label): void
    {
        try {
            DB::unprepared($sql);
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "\n  ! %s NOT applied: %s\n"
                . "    (Hall schema immutability/freeze guard not enforced at DB level on this host.)\n\n",
                $label,
                $e->getMessage()
            ));
        }
    }

    public function down(): void
    {
        // Deliberately irreversible: dropping these guards would reopen mutation
        // and deletion of versions that may already be referenced by sold tickets.
    }
};
