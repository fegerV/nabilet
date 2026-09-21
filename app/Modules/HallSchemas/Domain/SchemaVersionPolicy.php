<?php

declare(strict_types=1);

namespace App\Modules\HallSchemas\Domain;

/**
 * What may happen to a hall schema version, and what must be refused (ТЗ §20, §46).
 *
 * THREE RULES THAT THE DATABASE DOES NOT COVER, which is why this class exists:
 *
 *  1. ONE PUBLISHED VERSION PER HALL. `uq_schema_hall_version` is (hall_id,
 *     version) — it stops duplicate version NUMBERS, not duplicate published
 *     STATES. Nothing in the schema prevents two published versions of one hall,
 *     and if that happens a session has two candidate seat maps and the "correct"
 *     one becomes whichever row a query happened to return first. So the rule is
 *     enforced here and recorded as a bundle gap.
 *
 *  2. YOU CANNOT PUBLISH AN EMPTY MAP. `schema_json` is NOT NULL, but `{}` is a
 *     perfectly valid NOT NULL value. A published empty hall would sell sessions
 *     with zero seats and no error anywhere.
 *
 *  3. ARCHIVING IS THE ONLY WAY TO RETIRE A MAP, and it requires having been
 *     published. Editing is always "duplicate to a new version", never in place —
 *     which is what `nextVersionNumber()` exists to serve.
 */
final class SchemaVersionPolicy
{
    public const REASON_FROZEN = 'frozen';
    public const REASON_NOT_DRAFT = 'not_draft';
    public const REASON_EMPTY_PAYLOAD = 'empty_payload';
    public const REASON_ANOTHER_VERSION_PUBLISHED = 'another_version_published';
    public const REASON_NOT_PUBLISHED = 'not_published';

    public function canEditPayload(SchemaVersion $version): VersionDecision
    {
        if (! $version->canMutatePayload()) {
            return VersionDecision::refuse(self::REASON_FROZEN);
        }

        return VersionDecision::allow();
    }

    /**
     * @param list<SchemaVersion> $versions every version of this hall, including $version
     */
    public function canPublish(SchemaVersion $version, array $versions): VersionDecision
    {
        if ($version->isArchived()) {
            return VersionDecision::refuse(self::REASON_NOT_DRAFT);
        }

        if (! $version->isDraft()) {
            return VersionDecision::refuse(self::REASON_NOT_DRAFT);
        }

        if (! $version->hasPayload) {
            return VersionDecision::refuse(self::REASON_EMPTY_PAYLOAD);
        }

        foreach ($versions as $other) {
            // Only versions of THIS hall compete. The candidate carries its hall,
            // so scoping is derived rather than passed — the alternative, a
            // separate $hallId argument, invites the caller to pass one that
            // disagrees with the version being published.
            if ($other->hallId !== $version->hallId) {
                continue;
            }

            if ($other->id !== $version->id && $other->isPublished()) {
                return VersionDecision::refuse(self::REASON_ANOTHER_VERSION_PUBLISHED);
            }
        }

        return VersionDecision::allow();
    }

    public function canArchive(SchemaVersion $version): VersionDecision
    {
        if (! $version->isPublished()) {
            return VersionDecision::refuse(self::REASON_NOT_PUBLISHED);
        }

        return VersionDecision::allow();
    }

    /**
     * The number the next version of this hall must carry.
     *
     * Max + 1, not count + 1: if versions 1 and 2 were published and 2 then
     * deleted (ON DELETE CASCADE would not allow it while sessions reference it,
     * but an admin can), counting would hand out a number that already existed and
     * collide with uq_schema_hall_version.
     *
     * Scoped by hall for the same reason as publishing: version numbers are unique
     * per hall (uq_schema_hall_version), so another hall's highest number must not
     * inflate this one.
     *
     * @param list<SchemaVersion> $versions
     */
    public function nextVersionNumber(array $versions, int $hallId): int
    {
        $max = 0;

        foreach ($versions as $version) {
            if ($version->hallId !== $hallId) {
                continue;
            }

            if ($version->version > $max) {
                $max = $version->version;
            }
        }

        return $max + 1;
    }

    /**
     * @param list<SchemaVersion> $versions
     */
    public function publishedVersion(array $versions, int $hallId): ?SchemaVersion
    {
        foreach ($versions as $version) {
            if ($version->hallId !== $hallId) {
                continue;
            }

            if ($version->isPublished()) {
                return $version;
            }
        }

        return null;
    }
}
