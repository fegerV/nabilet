<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersion;
use Nabilet\Modules\HallSchemas\Domain\SchemaVersionState;
use Nabilet\Modules\Sessions\Domain\SeatingDecision;
use Nabilet\Modules\Sessions\Domain\SessionSeating;
use Nabilet\Modules\Sessions\Domain\SessionSeatingPolicy;
use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;
use Nabilet\Tests\Support\TestCase;

/**
 * What may be done to a session's hall map (ТЗ §13, §46).
 *
 * Both gaps these rules close were reproduced against MySQL 8.4 first: a session
 * pointing at a draft schema version was accepted, and a session with inventory
 * had its schema_version_id changed to another version — accepted, leaving
 * `session_points_at = 2` while `seat_belongs_to = 1`.
 */
final class SessionSeatingTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00');
    }

    private function version(
        int $id,
        int $hallId,
        int $number = 1,
        string $status = SchemaVersionState::PUBLISHED,
    ): SchemaVersion {
        return new SchemaVersion(
            id: $id,
            hallId: $hallId,
            version: $number,
            status: $status,
            hasPayload: true,
            publishedAt: $status === SchemaVersionState::DRAFT ? null : $this->now,
        );
    }

    private function seating(
        SchemaVersion $schema,
        int $inventory = 0,
        string $status = SessionStateMachine::SCHEDULED,
        int $hallId = 1,
    ): SessionSeating {
        return new SessionSeating(
            sessionId: 100,
            hallId: $hallId,
            status: $status,
            schemaVersion: $schema,
            inventoryCount: $inventory,
        );
    }

    // ── binding a map to a session ───────────────────────────────────────────

    public function testASessionBindsToAVersionOfItsOwnHall(): void
    {
        $decision = (new SessionSeatingPolicy())->canBind(
            $this->seating($this->version(1, 1)),
            $this->version(2, 1, 2)
        );

        $this->assertTrue($decision->isAllowed());
    }

    /** The seats would not be in the room. */
    public function testASessionCannotBindToAnotherHallsMap(): void
    {
        $decision = (new SessionSeatingPolicy())->canBind(
            $this->seating($this->version(1, 1)),
            $this->version(2, 9, 2)
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SeatingDecision::WRONG_HALL, $decision->verdict);
    }

    // ── opening sales ────────────────────────────────────────────────────────

    public function testSalesOpenOnAPublishedMap(): void
    {
        $decision = (new SessionSeatingPolicy())->canOpenSales(
            $this->seating($this->version(1, 1, 1, SchemaVersionState::PUBLISHED))
        );

        $this->assertTrue($decision->isAllowed());
    }

    /** The defect: MySQL accepted a session selling from a draft map. */
    public function testSalesDoNotOpenOnADraftMap(): void
    {
        $decision = (new SessionSeatingPolicy())->canOpenSales(
            $this->seating($this->version(1, 1, 1, SchemaVersionState::DRAFT))
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SeatingDecision::SCHEMA_NOT_PUBLISHED, $decision->verdict);
        $this->assertStringContainsString('edited while seats are on sale', (string) $decision->reason);
    }

    /**
     * Archived is not draft. A hall supersedes a layout by archiving it, and
     * sessions already selling from it keep selling. Rejecting archived here
     * would break every session running on a retired-but-valid map.
     */
    public function testSalesStillOpenOnAnArchivedMap(): void
    {
        $decision = (new SessionSeatingPolicy())->canOpenSales(
            $this->seating($this->version(1, 1, 1, SchemaVersionState::ARCHIVED))
        );

        $this->assertTrue($decision->isAllowed());
    }

    // ── rebinding ────────────────────────────────────────────────────────────

    public function testAMapCanBeChangedBeforeAnythingIsGenerated(): void
    {
        $decision = (new SessionSeatingPolicy())->canRebind(
            $this->seating($this->version(1, 1, 1), inventory: 0),
            $this->version(2, 1, 2)
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertTrue($decision->requiresWrite());
    }

    /**
     * The defect: MySQL accepted the UPDATE. Every inventory row names a seat
     * from the old map, and nothing downstream notices the mismatch.
     */
    public function testAMapCannotBeChangedOnceInventoryExists(): void
    {
        $decision = (new SessionSeatingPolicy())->canRebind(
            $this->seating($this->version(1, 1, 1), inventory: 1),
            $this->version(2, 1, 2)
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SeatingDecision::INVENTORY_EXISTS, $decision->verdict);
        $this->assertStringContainsString('outside its own map', (string) $decision->reason);
    }

    public function testAMapCannotBeChangedToADraft(): void
    {
        $decision = (new SessionSeatingPolicy())->canRebind(
            $this->seating($this->version(1, 1, 1), inventory: 0),
            $this->version(2, 1, 2, SchemaVersionState::DRAFT)
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SeatingDecision::SCHEMA_NOT_PUBLISHED, $decision->verdict);
    }

    public function testAMapCannotBeChangedToAnotherHall(): void
    {
        $decision = (new SessionSeatingPolicy())->canRebind(
            $this->seating($this->version(1, 1, 1), inventory: 0),
            $this->version(2, 9, 2)
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SeatingDecision::WRONG_HALL, $decision->verdict);
    }

    /**
     * Saving the same form twice is not a rebind. Reporting it as one would
     * write an UPDATE that changes nothing and bumps `updated_at`.
     */
    public function testRebindingToTheSameVersionIsANoOp(): void
    {
        $decision = (new SessionSeatingPolicy())->canRebind(
            $this->seating($this->version(1, 1, 1), inventory: 5),
            $this->version(1, 1, 1)
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(SeatingDecision::NO_CHANGE, $decision->verdict);
        $this->assertFalse($decision->requiresWrite());
    }

    /** A session that happened is record, not configuration. */
    public function testACompletedSessionCannotChangeItsMap(): void
    {
        $policy = new SessionSeatingPolicy();

        foreach ([SessionStateMachine::COMPLETED, SessionStateMachine::CANCELLED] as $status) {
            $decision = $policy->canRebind(
                $this->seating($this->version(1, 1, 1), inventory: 0, status: $status),
                $this->version(2, 1, 2)
            );

            $this->assertFalse($decision->isAllowed(), sprintf('%s must refuse a rebind', $status));
            $this->assertSame(SeatingDecision::SESSION_TERMINAL, $decision->verdict);
        }
    }

    /**
     * When two things are wrong, report the one that was decided first. Sold
     * inventory outranks a draft target: the seats are the fact that cannot be
     * undone, the draft is a state someone can change.
     */
    public function testInventoryOutranksADraftTarget(): void
    {
        $decision = (new SessionSeatingPolicy())->canRebind(
            $this->seating($this->version(1, 1, 1), inventory: 3),
            $this->version(2, 1, 2, SchemaVersionState::DRAFT)
        );

        $this->assertSame(SeatingDecision::INVENTORY_EXISTS, $decision->verdict);
    }

    // ── value object ─────────────────────────────────────────────────────────

    public function testInventoryOfAnySizeBlocksRebinding(): void
    {
        $policy = new SessionSeatingPolicy();

        $this->assertFalse($this->seating($this->version(1, 1), inventory: 0)->hasInventory());
        $this->assertTrue($this->seating($this->version(1, 1), inventory: 1)->hasInventory());

        $blocked = $policy->canRebind(
            $this->seating($this->version(1, 1, 1), inventory: 1),
            $this->version(2, 1, 2)
        );
        $this->assertFalse($blocked->isAllowed());
    }

    public function testOnlyCompletedAndCancelledAreTerminal(): void
    {
        foreach ([SessionStateMachine::DRAFT, SessionStateMachine::SCHEDULED,
            SessionStateMachine::ON_SALE, SessionStateMachine::SOLD_OUT,
            SessionStateMachine::CLOSED] as $status) {
            $this->assertFalse(
                $this->seating($this->version(1, 1), status: $status)->isTerminal(),
                sprintf('%s must not be terminal', $status)
            );
        }

        $this->assertTrue($this->seating($this->version(1, 1), status: SessionStateMachine::COMPLETED)->isTerminal());
        $this->assertTrue($this->seating($this->version(1, 1), status: SessionStateMachine::CANCELLED)->isTerminal());
    }

    public function testANegativeInventoryCountIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->seating($this->version(1, 1), inventory: -1)
        );
    }
}
