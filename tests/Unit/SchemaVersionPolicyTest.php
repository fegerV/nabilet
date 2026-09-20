<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersion;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersionPolicy;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersionState;
use Nabilet\Tests\Support\TestCase;

/**
 * The rules around publishing a seat map (ТЗ §20, §46, §98).
 *
 * Two of these rules exist because the schema does not cover them. A published
 * version is immutable in the database, but nothing in the database stops TWO
 * published versions of one hall, and nothing stops publishing `{}`. Both are
 * quiet ways to sell tickets against a map that is either ambiguous or empty.
 *
 * The third reason this file matters: without `canEditPayload` the immutability
 * rule surfaces as a MySQL constraint violation from inside an UPDATE — after the
 * editor has already told the organizer their work was saved.
 */
final class SchemaVersionPolicyTest extends TestCase
{
    private const HALL = 7;

    private function version(
        int $id,
        int $version,
        string $status = SchemaVersionState::DRAFT,
        bool $hasPayload = true,
    ): SchemaVersion {
        $publishedAt = $status === SchemaVersionState::DRAFT
            ? null
            : new \DateTimeImmutable('2026-09-20 10:00:00');

        return new SchemaVersion($id, self::HALL, $version, $status, $hasPayload, $publishedAt);
    }

    // ── editing ──────────────────────────────────────────────────────────────

    public function testADraftCanBeEdited(): void
    {
        $decision = (new SchemaVersionPolicy())->canEditPayload($this->version(1, 1));

        $this->assertTrue($decision->isAllowed());
        $this->assertNull($decision->reason());
    }

    /** This is the most common mistake in a seat-map editor. */
    public function testAPublishedVersionCannotBeEditedInPlace(): void
    {
        $decision = (new SchemaVersionPolicy())->canEditPayload(
            $this->version(1, 1, SchemaVersionState::PUBLISHED)
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SchemaVersionPolicy::REASON_FROZEN, $decision->reason());
        $this->assertStringContainsString('Duplicate it', $decision->message());
    }

    /** The trigger freezes `archived` too, and so must we. */
    public function testAnArchivedVersionIsFrozenAsWell(): void
    {
        $decision = (new SchemaVersionPolicy())->canEditPayload(
            $this->version(1, 1, SchemaVersionState::ARCHIVED)
        );

        $this->assertSame(SchemaVersionPolicy::REASON_FROZEN, $decision->reason());
    }

    // ── publishing ───────────────────────────────────────────────────────────

    public function testADraftWithAMapCanBePublished(): void
    {
        $candidate = $this->version(1, 1);

        $decision = (new SchemaVersionPolicy())->canPublish($candidate, [$candidate]);

        $this->assertTrue($decision->isAllowed());
    }

    /** `schema_json` is NOT NULL, but `{}` is a valid NOT NULL value. */
    public function testAnEmptyMapCannotBePublished(): void
    {
        $candidate = $this->version(1, 1, SchemaVersionState::DRAFT, false);

        $decision = (new SchemaVersionPolicy())->canPublish($candidate, [$candidate]);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SchemaVersionPolicy::REASON_EMPTY_PAYLOAD, $decision->reason());
    }

    /**
     * uq_schema_hall_version is (hall_id, version) — it stops duplicate numbers,
     * not duplicate published states. Two published maps would leave every session
     * with two candidate seat maps.
     */
    public function testAHallCannotHaveTwoPublishedVersions(): void
    {
        $published = $this->version(1, 1, SchemaVersionState::PUBLISHED);
        $candidate = $this->version(2, 2);

        $decision = (new SchemaVersionPolicy())->canPublish($candidate, [$published, $candidate]);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SchemaVersionPolicy::REASON_ANOTHER_VERSION_PUBLISHED, $decision->reason());
    }

    /** Once the old map is archived, the new one may go live. */
    public function testPublishingIsAllowedAfterTheOldVersionIsArchived(): void
    {
        $archived = $this->version(1, 1, SchemaVersionState::ARCHIVED);
        $candidate = $this->version(2, 2);

        $decision = (new SchemaVersionPolicy())->canPublish($candidate, [$archived, $candidate]);

        $this->assertTrue($decision->isAllowed());
    }

    public function testCandidatesFromAnotherHallDoNotBlockPublishing(): void
    {
        $mine = $this->version(2, 2);
        $otherHall = new SchemaVersion(
            1,
            99,
            1,
            SchemaVersionState::PUBLISHED,
            true,
            new \DateTimeImmutable('2026-09-20 10:00:00'),
        );

        $decision = (new SchemaVersionPolicy())->canPublish($mine, [$otherHall, $mine]);

        $this->assertTrue($decision->isAllowed(), 'only versions of THIS hall compete');
    }

    public function testAnArchivedVersionCannotBeRepublished(): void
    {
        $archived = $this->version(1, 1, SchemaVersionState::ARCHIVED);

        $decision = (new SchemaVersionPolicy())->canPublish($archived, [$archived]);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SchemaVersionPolicy::REASON_NOT_DRAFT, $decision->reason());
    }

    // ── archiving ────────────────────────────────────────────────────────────

    public function testAPublishedVersionCanBeArchived(): void
    {
        $decision = (new SchemaVersionPolicy())->canArchive(
            $this->version(1, 1, SchemaVersionState::PUBLISHED)
        );

        $this->assertTrue($decision->isAllowed());
    }

    public function testADraftCannotBeArchived(): void
    {
        $decision = (new SchemaVersionPolicy())->canArchive($this->version(1, 1));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SchemaVersionPolicy::REASON_NOT_PUBLISHED, $decision->reason());
    }

    public function testAnArchivedVersionStaysArchived(): void
    {
        $decision = (new SchemaVersionPolicy())->canArchive(
            $this->version(1, 1, SchemaVersionState::ARCHIVED)
        );

        $this->assertSame(SchemaVersionPolicy::REASON_NOT_PUBLISHED, $decision->reason());
    }

    // ── version numbering ────────────────────────────────────────────────────

    public function testTheNextVersionFollowsTheHighestExistingOne(): void
    {
        $versions = [$this->version(1, 1), $this->version(2, 2, SchemaVersionState::PUBLISHED)];

        $this->assertSame(3, (new SchemaVersionPolicy())->nextVersionNumber($versions, self::HALL));
    }

    /** Max + 1, not count + 1: a gap must not hand out a number already used. */
    public function testNumberingSurvivesAGap(): void
    {
        $versions = [$this->version(1, 1), $this->version(2, 5)];

        $this->assertSame(6, (new SchemaVersionPolicy())->nextVersionNumber($versions, self::HALL));
    }

    public function testTheFirstVersionIsNumberedOne(): void
    {
        $this->assertSame(1, (new SchemaVersionPolicy())->nextVersionNumber([], self::HALL));
    }

    /** Another hall's numbering must not leak into this one. */
    public function testNumberingIgnoresOtherHalls(): void
    {
        $versions = [
            $this->version(1, 1),
            new SchemaVersion(9, 4242, 40, SchemaVersionState::PUBLISHED, true, new \DateTimeImmutable('2026-09-20 10:00:00')),
        ];

        $this->assertSame(2, (new SchemaVersionPolicy())->nextVersionNumber($versions, self::HALL));
    }

    public function testThePublishedVersionCanBeFound(): void
    {
        $published = $this->version(2, 2, SchemaVersionState::PUBLISHED);
        $versions = [$this->version(1, 1), $published, $this->version(3, 3, SchemaVersionState::ARCHIVED)];

        $this->assertSame($published, (new SchemaVersionPolicy())->publishedVersion($versions, self::HALL));
        $this->assertNull((new SchemaVersionPolicy())->publishedVersion([$versions[0]], self::HALL));
    }

    // ── the state vocabulary ─────────────────────────────────────────────────

    public function testTheLifecycleIsDraftThenPublishedThenArchived(): void
    {
        $this->assertSame([SchemaVersionState::PUBLISHED], SchemaVersionState::transitionsFrom(SchemaVersionState::DRAFT));
        $this->assertSame([SchemaVersionState::ARCHIVED], SchemaVersionState::transitionsFrom(SchemaVersionState::PUBLISHED));
        $this->assertSame([], SchemaVersionState::transitionsFrom(SchemaVersionState::ARCHIVED));
    }

    /** Mirrors trg_schema_version_immutable — change one, change both. */
    public function testTheFrozenFieldsMatchTheTrigger(): void
    {
        $this->assertSame(
            ['schema_json', 'version', 'hall_id', 'width', 'height', 'background_url'],
            SchemaVersionState::FROZEN_FIELDS
        );
    }

    public function testDraftIsTheOnlyUnfrozenState(): void
    {
        $this->assertFalse(SchemaVersionState::isFrozen(SchemaVersionState::DRAFT));
        $this->assertTrue(SchemaVersionState::isFrozen(SchemaVersionState::PUBLISHED));
        $this->assertTrue(SchemaVersionState::isFrozen(SchemaVersionState::ARCHIVED));
    }

    // ── invariants ───────────────────────────────────────────────────────────

    public function testAStatusOutsideTheCheckIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new SchemaVersion(1, self::HALL, 1, 'retired', true, null)
        );
    }

    /** A published version with no published_at is an inconsistent row. */
    public function testAPublishedVersionMustCarryItsTimestamp(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new SchemaVersion(1, self::HALL, 1, SchemaVersionState::PUBLISHED, true, null)
        );
    }

    public function testVersionNumbersStartAtOne(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => SchemaVersion::draft(1, self::HALL, 0)
        );
    }
}
