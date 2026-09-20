<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Inventory\Domain\HoldWindow;
use Nabilet\Modules\Inventory\Domain\InventoryStock;
use Nabilet\Modules\Inventory\Domain\ReservationPolicy;
use Nabilet\Modules\Inventory\Domain\SeatHold;
use Nabilet\Modules\Orders\Domain\CartCheckout;
use Nabilet\Modules\Orders\Domain\CartState;
use Nabilet\Modules\Orders\Domain\CheckoutLine;
use Nabilet\Modules\Orders\Domain\OrderPlacement;
use Nabilet\Tests\Support\TestCase;

/**
 * The join where carts, holds and prices meet — and where the expensive bugs are.
 *
 * Everything here is written against values, not against a database or a clock:
 * "the hold expired eleven minutes ago" is passed in as a timestamp. That is the
 * only way to test the rules that actually matter, because in production these
 * races happen in a window of seconds and cannot be reproduced on demand.
 *
 * The three failures this file exists to prevent:
 *   1. selling seats whose hold already lapsed back into the pool;
 *   2. charging a customer more than the total they agreed to;
 *   3. converting one cart into two orders.
 */
final class OrderPlacementTest extends TestCase
{
    private const ITEM_SEAT = 101;
    private const ITEM_STANDING = 202;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-20 12:00:00');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function hold(int $itemId, int $quantity = 1, ?int $ageSeconds = null): SeatHold
    {
        $openedAt = $this->now->modify(sprintf('-%d seconds', $ageSeconds ?? 0));

        return new SeatHold(
            id: 'hold_' . $itemId . '_' . ($ageSeconds ?? 0),
            inventoryItemId: $itemId,
            quantity: $quantity,
            window: HoldWindow::openingAt($openedAt, 600, 30),
        );
    }

    /** @param list<CheckoutLine> $lines */
    private function checkout(array $lines, array $holds, string $status = CartState::ACTIVE): CartCheckout
    {
        return new CartCheckout(
            cartId: 'cart_1',
            cartStatus: $status,
            lines: $lines,
            holds: $holds,
            now: $this->now,
        );
    }

    private function seatLine(int $price = 500000, ?int $current = null): CheckoutLine
    {
        return new CheckoutLine(
            self::ITEM_SEAT,
            1,
            Money::of($price),
            Money::of($current ?? $price),
            InventoryStock::seat(),
        );
    }

    // ── the happy path ───────────────────────────────────────────────────────

    public function testAnActiveCartWithALiveHoldCanBeCheckedOut(): void
    {
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$this->seatLine()], [$this->hold(self::ITEM_SEAT)])
        );

        $this->assertTrue($verdict->isOk());
        $this->assertNull($verdict->reason());
        $this->assertSame(500000, $verdict->subtotal()->minorUnits());
        $this->assertCount(1, $verdict->holdsToConvert());
        $this->assertSame([], $verdict->warnings());
    }

    public function testSubtotalSumsEveryLine(): void
    {
        $lines = [
            $this->seatLine(500000),
            new CheckoutLine(
                self::ITEM_STANDING,
                3,
                Money::of(100000),
                Money::of(100000),
                InventoryStock::standing(100, 100),
            ),
        ];

        $verdict = (new OrderPlacement())->assess($this->checkout($lines, [
            $this->hold(self::ITEM_SEAT),
            $this->hold(self::ITEM_STANDING, 3),
        ]));

        $this->assertTrue($verdict->isOk());
        $this->assertSame(800000, $verdict->subtotal()->minorUnits(), '500000 + 3 x 100000');
    }

    // ── a cart may only be converted once ────────────────────────────────────

    public function testAnAlreadyConvertedCartIsRefused(): void
    {
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$this->seatLine()], [$this->hold(self::ITEM_SEAT)], CartState::CONVERTED)
        );

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_CART_NOT_ACTIVE, $verdict->reason());
    }

    public function testAnAbandonedCartIsRefused(): void
    {
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$this->seatLine()], [$this->hold(self::ITEM_SEAT)], CartState::ABANDONED)
        );

        $this->assertSame(OrderPlacement::REASON_CART_NOT_ACTIVE, $verdict->reason());
    }

    /** A broken cart is still reported as "not active" — fix the cart first. */
    public function testCartStatusOutranksEveryOtherProblem(): void
    {
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$this->seatLine(100, 999)], [], CartState::CONVERTED)
        );

        $this->assertSame(OrderPlacement::REASON_CART_NOT_ACTIVE, $verdict->reason());
    }

    public function testAnEmptyCartIsRefused(): void
    {
        $verdict = (new OrderPlacement())->assess($this->checkout([], []));

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_EMPTY_CART, $verdict->reason());
    }

    // ── price drift ──────────────────────────────────────────────────────────

    public function testAPriceIncreaseBlocksCheckout(): void
    {
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$this->seatLine(500000, 550000)], [$this->hold(self::ITEM_SEAT)])
        );

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_PRICE_INCREASED, $verdict->reason());
    }

    /** Charging less than the customer was shown is fine, but say so. */
    public function testAPriceReductionIsChargedAndWarned(): void
    {
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$this->seatLine(500000, 450000)], [$this->hold(self::ITEM_SEAT)])
        );

        $this->assertTrue($verdict->isOk());
        $this->assertSame(450000, $verdict->subtotal()->minorUnits());
        $this->assertContains(OrderPlacement::WARNING_PRICE_REDUCED, $verdict->warnings());
    }

    public function testPriceDriftSignIsCorrect(): void
    {
        $this->assertSame(50000, $this->seatLine(500000, 550000)->priceDrift());
        $this->assertSame(-50000, $this->seatLine(500000, 450000)->priceDrift());
        $this->assertSame(0, $this->seatLine()->priceDrift());

        $this->assertTrue($this->seatLine(500000, 550000)->priceRose());
        $this->assertTrue($this->seatLine(500000, 450000)->priceFell());
    }

    /** Never charge more than the number the customer agreed to. */
    public function testEffectivePriceNeverExceedsTheSnapshot(): void
    {
        $up = $this->seatLine(500000, 550000);
        $down = $this->seatLine(500000, 450000);

        $this->assertSame(500000, $up->effectiveUnitPrice()->minorUnits());
        $this->assertSame(450000, $down->effectiveUnitPrice()->minorUnits());
    }

    // ── holds ────────────────────────────────────────────────────────────────

    public function testAHoldPastItsGraceWindowIsRefused(): void
    {
        // Opened 640s ago: expired at 600, releasable at 630. The seat may by now
        // belong to somebody else.
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$this->seatLine()], [$this->hold(self::ITEM_SEAT, 1, 640)])
        );

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_HOLD_EXPIRED, $verdict->reason());
    }

    /**
     * Inside the grace window the units are still debited to this cart and nobody
     * else could have taken them, so refusing would lose a real sale.
     */
    public function testAHoldInsideItsGraceWindowStillConvertsWithAWarning(): void
    {
        // Opened 610s ago: expired (600) but not yet releasable (630).
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$this->seatLine()], [$this->hold(self::ITEM_SEAT, 1, 610)])
        );

        $this->assertTrue($verdict->isOk());
        $this->assertContains(OrderPlacement::WARNING_HOLD_OVERRUN, $verdict->warnings());
    }

    public function testALineWithNoHoldAtAllIsRefusedAsMissing(): void
    {
        $verdict = (new OrderPlacement())->assess($this->checkout([$this->seatLine()], []));

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_HOLD_MISSING, $verdict->reason());
    }

    public function testAReleasedHoldCountsAsNeverHeld(): void
    {
        $released = new SeatHold(
            id: 'hold_released',
            inventoryItemId: self::ITEM_SEAT,
            quantity: 1,
            window: HoldWindow::openingAt($this->now, 600, 30),
            releasedAt: $this->now,
        );

        $verdict = (new OrderPlacement())->assess($this->checkout([$this->seatLine()], [$released]));

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_HOLD_MISSING, $verdict->reason());
    }

    public function testAStandingLineNeedsAHoldCoveringTheWholeQuantity(): void
    {
        $line = new CheckoutLine(
            self::ITEM_STANDING,
            4,
            Money::of(100000),
            Money::of(100000),
            InventoryStock::standing(100, 100),
        );

        // only 2 of the 4 requested units are held
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$line], [$this->hold(self::ITEM_STANDING, 2)])
        );

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_HOLD_MISSING, $verdict->reason());
    }

    /**
     * Partially lapsed: two units are still held, two went back to the pool.
     * "Expired" is the actionable answer — the customer must re-select, and no
     * amount of adding to the cart will recover those two units.
     */
    public function testAPartiallyLapsedHoldIsReportedAsExpired(): void
    {
        $line = new CheckoutLine(
            self::ITEM_STANDING,
            4,
            Money::of(100000),
            Money::of(100000),
            InventoryStock::standing(100, 100),
        );

        $verdict = (new OrderPlacement())->assess($this->checkout([$line], [
            $this->hold(self::ITEM_STANDING, 2),          // still live
            $this->hold(self::ITEM_STANDING, 2, 640),    // lapsed past grace
        ]));

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_HOLD_EXPIRED, $verdict->reason());
    }

    /** Seats already handed to another customer are not a hold problem. */
    public function testSoldOutInventoryIsReportedAsUnavailable(): void
    {
        $line = new CheckoutLine(
            self::ITEM_SEAT,
            1,
            Money::of(500000),
            Money::of(500000),
            InventoryStock::seat(false),
        );

        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$line], [$this->hold(self::ITEM_SEAT)])
        );

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_INSUFFICIENT_AVAILABILITY, $verdict->reason());
    }

    // ── quantity rules ───────────────────────────────────────────────────────

    public function testASeatCannotBeBoughtTwiceOnOneLine(): void
    {
        $line = new CheckoutLine(
            self::ITEM_SEAT,
            2,
            Money::of(500000),
            Money::of(500000),
            InventoryStock::seat(),
        );

        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$line], [$this->hold(self::ITEM_SEAT, 2)])
        );

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_QUANTITY_EXCEEDED, $verdict->reason());
    }

    public function testThePerOrderCapIsEnforced(): void
    {
        $line = new CheckoutLine(
            self::ITEM_STANDING,
            9,
            Money::of(100000),
            Money::of(100000),
            InventoryStock::standing(100, 100),
        );

        $verdict = (new OrderPlacement(new ReservationPolicy(8)))->assess(
            $this->checkout([$line], [$this->hold(self::ITEM_STANDING, 9)])
        );

        $this->assertFalse($verdict->isOk());
        $this->assertSame(OrderPlacement::REASON_QUANTITY_EXCEEDED, $verdict->reason());
    }

    // ── precedence ───────────────────────────────────────────────────────────

    /** The customer can act on "your seats are gone" before "the price moved". */
    public function testAnExpiredHoldOutranksAPriceChange(): void
    {
        $line = $this->seatLine(500000, 550000);

        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$line], [$this->hold(self::ITEM_SEAT, 1, 640)])
        );

        $this->assertSame(OrderPlacement::REASON_HOLD_EXPIRED, $verdict->reason());
    }

    /** One line broken two ways reports both, and no duplicate reasons. */
    public function testProblemsAreReportedPerLineAndDeduplicated(): void
    {
        $line = new CheckoutLine(
            self::ITEM_SEAT,
            1,
            Money::of(500000),
            Money::of(550000),
            InventoryStock::seat(false),
        );

        $verdict = (new OrderPlacement())->assess($this->checkout([$line], []));

        $reasons = array_column($verdict->problems(), 'reason');

        $this->assertContains(OrderPlacement::REASON_INSUFFICIENT_AVAILABILITY, $reasons);
        $this->assertContains(OrderPlacement::REASON_HOLD_MISSING, $reasons);
        $this->assertContains(OrderPlacement::REASON_PRICE_INCREASED, $reasons);
        $this->assertSame(
            count($reasons),
            count(array_unique($reasons)),
            'the same reason must not appear twice for one line'
        );
    }

    // ── the error surface ────────────────────────────────────────────────────

    public function testARefusalCarriesTheRightErrorCode(): void
    {
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$this->seatLine()], [$this->hold(self::ITEM_SEAT, 1, 640)])
        );

        $this->assertThrows(ConflictError::class, fn () => $verdict->throwIfRefused());
    }

    public function testASuccessfulVerdictDoesNotThrow(): void
    {
        $verdict = (new OrderPlacement())->assess(
            $this->checkout([$this->seatLine()], [$this->hold(self::ITEM_SEAT)])
        );

        $verdict->throwIfRefused();

        $this->assertTrue($verdict->isOk(), 'reaching this line means it did not throw');
    }

    // ── input invariants ─────────────────────────────────────────────────────

    public function testMixedCurrenciesAreRejectedAtConstruction(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new CartCheckout(
                'cart_1',
                CartState::ACTIVE,
                [
                    new CheckoutLine(self::ITEM_SEAT, 1, Money::of(500000, 'RUB'), Money::of(500000, 'RUB'), InventoryStock::seat()),
                    new CheckoutLine(self::ITEM_STANDING, 1, Money::of(5000, 'USD'), Money::of(5000, 'USD'), InventoryStock::standing(10, 10)),
                ],
                [],
                $this->now,
            )
        );
    }

    public function testALineWhoseSnapshotAndCurrentPriceDisagreeOnCurrencyIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new CheckoutLine(
                self::ITEM_SEAT,
                1,
                Money::of(500000, 'RUB'),
                Money::of(5000, 'USD'),
                InventoryStock::seat(),
            )
        );
    }

    // ── cart vocabulary ──────────────────────────────────────────────────────

    public function testOnlyAnActiveCartCanCheckOut(): void
    {
        $this->assertTrue(CartState::canCheckOut(CartState::ACTIVE));
        $this->assertFalse(CartState::canCheckOut(CartState::CONVERTED));
        $this->assertFalse(CartState::canCheckOut(CartState::ABANDONED));
        $this->assertFalse(CartState::canCheckOut(CartState::EXPIRED));
    }

    /** A converted cart is history: buying again means a new cart at new prices. */
    public function testEveryNonActiveCartStateIsTerminal(): void
    {
        $this->assertFalse(CartState::isTerminal(CartState::ACTIVE));
        $this->assertTrue(CartState::isTerminal(CartState::CONVERTED));
        $this->assertTrue(CartState::isTerminal(CartState::ABANDONED));
        $this->assertTrue(CartState::isTerminal(CartState::EXPIRED));
    }

    public function testAnUnknownCartStatusIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => CartState::transitionsFrom('paid')
        );
    }

    // ── hold lifecycle ───────────────────────────────────────────────────────

    public function testAConvertedHoldIsNeverConvertibleAgain(): void
    {
        $converted = new SeatHold(
            id: 'hold_conv',
            inventoryItemId: self::ITEM_SEAT,
            quantity: 1,
            window: HoldWindow::openingAt($this->now, 600, 30),
            convertedAt: $this->now,
        );

        $this->assertFalse($converted->isConvertibleAt($this->now));
        $this->assertTrue($converted->isConverted());
    }

    public function testAHoldCannotCoverZeroUnits(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new SeatHold('h', self::ITEM_SEAT, 0, HoldWindow::openingAt($this->now, 600, 30))
        );
    }
}
