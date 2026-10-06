<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The one invariant a CHECK constraint cannot express: published hall schema
 * versions are immutable.
 *
 * ТЗ §20/§98 — once a hall schema version leaves `draft`, its geometry is frozen.
 * Sold tickets reference that geometry (seat → row → sector names, coordinates on
 * the rendered map). Rewriting it after the fact would silently invalidate tickets
 * that customers already hold, so the database refuses the write rather than the
 * application hopefully remembering to.
 *
 * WHY A TRIGGER AND NOT `generated`/STORED COLUMNS
 *   The comparison needs OLD vs NEW across six columns, and it must apply to UPDATE
 *   only. A CHECK cannot see the previous row, and a generated column cannot reject
 *   a write. `SIGNAL SQLSTATE '45000'` is the only MySQL mechanism that does both.
 *
 * WHY `<=>` AND NOT `=`
 *   `background_url` is nullable. Plain `=` yields NULL on a NULL operand, and
 *   `NOT NULL` is NULL, which the IF would treat as "unchanged" — so clearing a
 *   published version's background would slip through. `<=>` is null-safe equality.
 *
 * DELIBERATELY NOT BLOCKED
 *   - `status` transitions: publishing and archiving are normal lifecycle moves.
 *   - `updated_at` bookkeeping.
 *   - any change while the version is still `draft`.
 *   A caller who needs a different geometry duplicates the version — that is the
 *   designed workflow, not a workaround.
 *
 * TARGET
 *   MySQL/MariaDB, using `SIGNAL SQLSTATE '45000'` — the only mechanism here that can
 *   both compare OLD against NEW and reject the write. MySQL 8.4 is the sole supported
 *   target. Any other driver is skipped loudly rather than half-applied: an
 *   immutability guarantee that quietly does nothing is worse than one that is
 *   visibly absent.
 *
 * DEPLOYMENT PREREQUISITE — READ BEFORE DEPLOYING
 *   Creating a trigger requires more than ALTER on the schema. With binary logging
 *   enabled, MySQL refuses `CREATE TRIGGER` from a user without the SUPER privilege
 *   unless `log_bin_trust_function_creators = 1`, and answers ERROR 1419. This was
 *   reproduced on MySQL 8.4.11 against a user holding ALL PRIVILEGES on its own
 *   database only — exactly what shared hosting grants:
 *
 *     ERROR 1419 (HY000): You do not have the SUPER privilege and binary logging is
 *     enabled (you *might* want to use the less safe log_bin_trust_function_creators
 *     variable)
 *
 *   So on shared hosting this migration either succeeds because the host already sets
 *   `log_bin_trust_function_creators=1` (common), or the CREATE TRIGGER fails with
 *   ERROR 1419. We no longer abort `php artisan migrate` over it: the trigger is
 *   defense-in-depth only (the application layer also guards immutability), so the
 *   migration catches the error, logs a warning, and lets the install proceed. The
 *   immutability guarantee is simply not enforced at the DB level on that host.
 */
return new class extends Migration
{
    private const TRIGGER = 'trg_schema_version_immutable';

    private const MESSAGE = 'Published hall schema versions are immutable (spec 20/98). Duplicate to a new version instead.';

    public function up(): void
    {
        if (! $this->supportsTriggers()) {
            fwrite(STDERR, sprintf(
                "\n  ! SKIPPED %s: driver \"%s\" has no supported trigger dialect.\n"
                . "    Hall schema immutability (TZ 20/98) is NOT enforced. Do not\n"
                . "    run production on this driver without an equivalent guard.\n\n",
                self::TRIGGER,
                DB::connection()->getDriverName()
            ));

            return;
        }

        $this->createMySqlTrigger();
    }

    private function createMySqlTrigger(): void
    {
        // DB::unprepared(), not statement(): a trigger body contains semicolons in
        // BEGIN...END. DELIMITER is a *client* directive, not server syntax, so it
        // is absent here — the body is sent as one statement.
        try {
            DB::unprepared(
                'DROP TRIGGER IF EXISTS ' . self::TRIGGER . ";\n"
                . 'CREATE TRIGGER ' . self::TRIGGER . "\n"
                . "BEFORE UPDATE ON hall_schema_versions\n"
                . "FOR EACH ROW\n"
                . "BEGIN\n"
                . "  IF OLD.status IN ('published', 'archived') THEN\n"
                . "    IF NOT (NEW.schema_json <=> OLD.schema_json)\n"
                . "       OR NOT (NEW.version <=> OLD.version)\n"
                . "       OR NOT (NEW.hall_id <=> OLD.hall_id)\n"
                . "       OR NOT (NEW.width <=> OLD.width)\n"
                . "       OR NOT (NEW.height <=> OLD.height)\n"
                . "       OR NOT (NEW.background_url <=> OLD.background_url)\n"
                . "    THEN\n"
                . "      SIGNAL SQLSTATE '45000'\n"
                . "        SET MESSAGE_TEXT = '" . self::MESSAGE . "';\n"
                . "    END IF;\n"
                . "  END IF;\n"
                . 'END'
            );
        } catch (\Throwable $e) {
            // Shared hosting (MySQL with binary logging, no SUPER, no
            // log_bin_trust_function_creators) answers ERROR 1419 on CREATE TRIGGER.
            // This trigger is defense-in-depth only — the application layer also
            // guards immutability — so we degrade gracefully instead of aborting
            // `php artisan migrate` (which would abort the whole install).
            fwrite(STDERR, sprintf(
                "\n  ! Trigger %s NOT created: %s\n"
                . "    Hall schema immutability (TZ 20/98) is not enforced at the DB level.\n\n",
                self::TRIGGER,
                $e->getMessage()
            ));
        }
    }

    private function supportsTriggers(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function down(): void
    {
        // Irreversible by design: dropping the trigger weakens a guarantee that
        // protects already-sold tickets.
    }
};
