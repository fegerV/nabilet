<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Inventory\Domain\HoldWindow;
use Nabilet\Modules\Inventory\Domain\InventoryStock;
use Nabilet\Modules\Inventory\Domain\ReservationPolicy;
use Nabilet\Tests\Support\TestCase;

/**
 * The quantity model behind "two people must not buy the same seat".
 *
 * The database enforces it (ck_inventory_available_qty, and the concurrency
 * matrix already passes 6/6 on MySQL). What these tests cover is the arithmetic
 * around it: what taking and returning units does, what a seat is allowed to be,
 * and when a hold may be swept — the parts a stored procedure cannot express and
 * a controller should not own.
 */
final class InventoryStockTest extends TestCase
{
    // ── the invariants ───────────────────────────────────────────────────────

    public function testAvailabilityCannotExceedCapacity(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => InventoryStock::of(10, 11, InventoryStock::TYPE_STANDING)
        );
    }

    public function testAvailabilityCannotBeNegative(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => InventoryStock::of(10, -1, InventoryStock::TYPE_STANDING)
        );
    }

    /** ck_inventory_seat_capacity: a seat has capacity 1, always. */
    public function testASeatAlwaysHasCapacityOne(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => InventoryStock::of(2, 2, InventoryStock::TYPE_SEAT)
        );

        $this->assertSame(1, InventoryStock::seat()->capacity());
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => InventoryStock::of(5, 5, 'balcony')
        );
    }

    // ── reserving and releasing ──────────────────────────────────────────────

    public function testReservingReducesAvailability(): void
    {
        $stock = InventoryStock::standing(100, 100)->reserve(4);

        $this->assertSame(96, $stock->available());
        $this->assertSame(100, $stock->capacity(), 'capacity never changes');
    }

    public function testReservingReturnsANewObject(): void
    {
        $original = InventoryStock::standing(10, 10);
        $after = $original->reserve(3);

        $this->assertSame(10, $original->available(), 'the original is untouched');
        $this->assertSame(7, $after->available());
    }

    public function testReservingMoreThanAvailableIsAConflict(): void
    {
        $this->assertThrows(
            ConflictError::class,
            fn () => InventoryStock::standing(10, 2)->reserve(3)
        );
    }

    public function testReservingZeroIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => InventoryStock::standing(10)->reserve(0)
        );
    }

    public function testASeatCanBeReservedExactlyOnce(): void
    {
        $taken = InventoryStock::seat()->reserve(1);

        $this->assertTrue($taken->isSoldOut());
        $this->assertFalse($taken->canReserve(1), 'the seat is gone; a second sale must fail');
    }

    public function testReleasingRestoresAvailability(): void
    {
        $stock = InventoryStock::standing(100, 96)->release(4);

        $this->assertSame(100, $stock->available());
    }

    /**
     * Over-releasing is capped, not fatal: the sweeper must not stop because one
     * row drifted. `available <= capacity` is the invariant that matters.
     */
    public function testReleasingBeyondCapacityIsCapped(): void
    {
        $stock = InventoryStock::standing(10, 9)->release(50);

        $this->assertSame(10, $stock->available());
    }

    public function testSoldOutIsZeroNotNegative(): void
    {
        $stock = InventoryStock::standing(3)->reserve(3);

        $this->assertSame(0, $stock->available());
        $this->assertTrue($stock->isSoldOut());
    }

    // ── hold window ──────────────────────────────────────────────────────────

    public function testDefaultHoldIsTenMinutes(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00');
        $window = HoldWindow::openingAt($now);

        $this->assertSame('2026-09-20 12:10:00', $window->expiresAt()->format('Y-m-d H:i:s'));
    }

    /** The spec gives 5–30 minutes; anything outside is refused, not clamped. */
    public function testTtlOutsideFiveToThirtyMinutesIsRejected(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00');

        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => HoldWindow::openingAt($now, 60)
        );

        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => HoldWindow::openingAt($now, 3600)
        );

        // The boundaries themselves are allowed.
        $this->assertNotNull(HoldWindow::openingAt($now, HoldWindow::MIN_TTL_SECONDS));
        $this->assertNotNull(HoldWindow::openingAt($now, HoldWindow::MAX_TTL_SECONDS));
    }

    /**
     * A buyer who hits "pay" at 09:59:59 must not lose the seat to a job that
     * runs at 10:00:00. Expiry and release are two different moments.
     */
    public function testGracePeriodDelaysReleaseButNotExpiry(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00');
        $window = HoldWindow::openingAt($now, 600, 30);

        $atExpiry = new \DateTimeImmutable('2026-09-20 12:10:00');
        $this->assertTrue($window->isExpiredAt($atExpiry));
        $this->assertFalse($window->isReleasableAt($atExpiry), 'still inside the grace period');

        $afterGrace = new \DateTimeImmutable('2026-09-20 12:10:30');
        $this->assertTrue($window->isReleasableAt($afterGrace));
    }

    public function testUnexpiredHoldIsNotReleasable(): void
    {
        $now = new \DateTimeImmutable('2026-09-20 12:00:00');
        $window = HoldWindow::openingAt($now);

        $this->assertFalse($window->isExpiredAt(new \DateTimeImmutable('2026-09-20 12:09:00')));
        $this->assertFalse($window->isReleasableAt(new \DateTimeImmutable('2026-09-20 12:09:00')));
    }

    // ── reservation policy ───────────────────────────────────────────────────

    public function testOneSeatIsAllowed(): void
    {
        $policy = new ReservationPolicy();

        $this->assertTrue($policy->allows(InventoryStock::seat(), 1));
    }

    /**
     * Asking for two seats is malformed, not "two units". Silently clamping it to
     * one would charge for one ticket while the customer believes they bought two.
     */
    public function testTwoOfASeatIsRefused(): void
    {
        $policy = new ReservationPolicy();

        $this->assertSame(
            ReservationPolicy::REASON_SEAT_QUANTITY_MUST_BE_ONE,
            $policy->refusalFor(InventoryStock::seat(), 2)
        );
    }

    public function testStandingZoneAcceptsMany(): void
    {
        $policy = new ReservationPolicy();

        $this->assertTrue($policy->allows(InventoryStock::standing(100), 4));
    }

    public function testAntiScalpingCapApplies(): void
    {
        $policy = new ReservationPolicy(8);

        $this->assertSame(
            ReservationPolicy::REASON_EXCEEDS_ORDER_LIMIT,
            $policy->refusalFor(InventoryStock::standing(100), 9)
        );

        $this->assertTrue($policy->allows(InventoryStock::standing(100), 8));
    }

    public function testInsufficientAvailabilityIsReportedDistinctly(): void
    {
        $policy = new ReservationPolicy();

        $this->assertSame(
            ReservationPolicy::REASON_INSUFFICIENT_AVAILABILITY,
            $policy->refusalFor(InventoryStock::standing(10, 2), 3)
        );
    }

    public function testRemainingAllowanceAccountsForWhatIsAlreadyHeld(): void
    {
        $policy = new ReservationPolicy(8);

        $this->assertSame(2, $policy->remainingAllowance(6));
        $this->assertSame(8, $policy->remainingAllowance(0));
        $this->assertSame(0, $policy->remainingAllowance(99), 'never negative');
    }

    public function testZeroQuantityIsRefused(): void
    {
        $policy = new ReservationPolicy();

        $this->assertSame(
            ReservationPolicy::REASON_QUANTITY_NOT_POSITIVE,
            $policy->refusalFor(InventoryStock::standing(10), 0)
        );
    }
}
