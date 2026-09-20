<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Cart\Domain\Cart;
use Nabilet\Modules\Cart\Domain\CartDecision;
use Nabilet\Modules\Cart\Domain\CartItem;
use Nabilet\Modules\Cart\Domain\CartPolicy;
use Nabilet\Modules\Inventory\Domain\HoldWindow;
use Nabilet\Modules\Inventory\Domain\SeatHold;
use Nabilet\Modules\Orders\Domain\CartState;
use Nabilet\Tests\Support\TestCase;

/**
 * What may go into a cart, and how long a cart may live (ТЗ §23, §24, §84).
 *
 * Every gap these rules close was reproduced against MySQL 8.4 first:
 *
 *   - a cart for session 1 accepted an inventory item belonging to session 2
 *     (`cart_items.inventory_item_id` checks the item exists, not whom it belongs to);
 *   - `total_price = 1` beside `unit_price = 100000, quantity = 2` was accepted
 *     (no CHECK ties the stored total to arithmetic);
 *   - `unit_price = -500000` was accepted (signed BIGINT, no CHECK);
 *   - `carts.expires_at = NULL` was accepted, and so was an expiry a month AFTER
 *     `sessions.starts_at`.
 */
final class CartTest extends TestCase
{
    private const SESSION_A = 1;
    private const SESSION_B = 2;
    private const ITEM_SEAT = 10;
    private const ITEM_STANDING = 20;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function money(int $minorUnits, string $currency = 'RUB'): Money
    {
        return Money::of($minorUnits, $currency);
    }

    private function item(
        int $inventoryItemId,
        int $sessionId = self::SESSION_A,
        int $quantity = 1,
        int $unitPrice = 100000,
        ?int $storedTotal = null,
    ): CartItem {
        return new CartItem(
            inventoryItemId: $inventoryItemId,
            sessionId: $sessionId,
            quantity: $quantity,
            unitPrice: $this->money($unitPrice),
            totalPrice: $this->money($storedTotal ?? $unitPrice * $quantity),
        );
    }

    /** @param list<CartItem> $items */
    private function cart(
        array $items = [],
        int $sessionId = self::SESSION_A,
        string $status = CartState::ACTIVE,
        ?\DateTimeImmutable $expiresAt = null,
        int|string $id = 1,
    ): Cart {
        return new Cart(
            id: $id,
            sessionId: $sessionId,
            status: $status,
            expiresAt: $expiresAt,
            items: $items,
        );
    }

    /** @param list<SeatHold> $holds */
    private function policy(array $holds = []): CartPolicy
    {
        return new CartPolicy($holds);
    }

    private function hold(int $inventoryItemId, int $ttlSeconds = 600, int $quantity = 1): SeatHold
    {
        return new SeatHold(
            id: $inventoryItemId,
            inventoryItemId: $inventoryItemId,
            quantity: $quantity,
            window: HoldWindow::openingAt($this->now, $ttlSeconds),
        );
    }

    // ── the line ─────────────────────────────────────────────────────────────

    public function testALineKnowsWhichSessionItBelongsTo(): void
    {
        $item = $this->item(self::ITEM_SEAT, self::SESSION_A);

        $this->assertTrue($item->belongsToSession(self::SESSION_A));
        $this->assertFalse($item->belongsToSession(self::SESSION_B));
    }

    public function testAStoredTotalThatMatchesArithmeticIsCoherent(): void
    {
        $item = $this->item(self::ITEM_STANDING, self::SESSION_A, 3, 250000, 750000);

        $this->assertTrue($item->hasCoherentMoney());
        $this->assertSame(0, $item->moneyDrift());
    }

    public function testAStoredTotalThatDisagreesIsDetectedRatherThanCorrected(): void
    {
        // Exactly the row MySQL accepted: total_price = 1 beside 100000 × 2.
        $item = $this->item(self::ITEM_STANDING, self::SESSION_A, 2, 100000, 1);

        $this->assertFalse($item->hasCoherentMoney());
        $this->assertSame(-199999, $item->moneyDrift());
        $this->assertSame(200000, $item->expectedTotal()->minorUnits());
    }

    public function testTheTotalIsDerivedWhenItIsNotSupplied(): void
    {
        $item = CartItem::of(self::ITEM_STANDING, self::SESSION_A, 4, $this->money(125000));

        $this->assertSame(500000, $item->totalPrice->minorUnits());
        $this->assertTrue($item->hasCoherentMoney());
    }

    public function testAZeroQuantityLineCannotBeBuilt(): void
    {
        // ck_cart_items_quantity does have this one; the object repeats it so it
        // cannot exist in a state the database would refuse.
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->item(self::ITEM_SEAT, self::SESSION_A, 0),
            'quantity 0 must be refused'
        );
    }

    public function testANegativeUnitPriceCannotBeBuilt(): void
    {
        // NOT constrained by the schema: unit_price is a signed BIGINT and
        // -500000 was accepted. A negative line is a refund dressed as a sale.
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->item(self::ITEM_SEAT, self::SESSION_A, 1, -500000, -500000),
            'a negative unit price must be refused'
        );
    }

    public function testALineWhoseTotalIsInAnotherCurrencyCannotBeBuilt(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new CartItem(
                inventoryItemId: self::ITEM_SEAT,
                sessionId: self::SESSION_A,
                quantity: 1,
                unitPrice: $this->money(100000, 'RUB'),
                totalPrice: $this->money(100000, 'USD'),
            ),
            'mixed currencies on one line must be refused'
        );
    }

    // ── the cart ─────────────────────────────────────────────────────────────

    public function testACleanCartHasNoStrayAndNoIncoherentItems(): void
    {
        $cart = $this->cart([
            $this->item(self::ITEM_SEAT),
            $this->item(self::ITEM_STANDING, self::SESSION_A, 2),
        ]);

        $this->assertFalse($cart->isEmpty());
        $this->assertCount(0, $cart->strayItems());
        $this->assertCount(0, $cart->incoherentItems());
        $this->assertSame(3, $cart->totalUnits());
    }

    public function testACartFindsItemsThatBelongToAnotherSession(): void
    {
        // The row MySQL accepted: cart for session 1, item of session 2.
        $cart = $this->cart([
            $this->item(self::ITEM_SEAT),
            $this->item(self::ITEM_STANDING, self::SESSION_B, 2),
        ]);

        $stray = $cart->strayItems();

        $this->assertCount(1, $stray);
        $this->assertSame(self::ITEM_STANDING, $stray[0]->inventoryItemId);
        $this->assertSame(self::SESSION_B, $stray[0]->sessionId);
    }

    public function testTheStoredTotalIsUsedNotTheCorrectedOne(): void
    {
        // Reporting the stored figure is the point: that is what gets charged if
        // nobody stops it. Recomputing here would make the defect invisible.
        $cart = $this->cart([$this->item(self::ITEM_STANDING, self::SESSION_A, 2, 100000, 1)]);

        $this->assertSame(1, $cart->storedTotal()->minorUnits());
    }

    public function testUnitsAreCountedPerItem(): void
    {
        $cart = $this->cart([
            $this->item(self::ITEM_SEAT),
            $this->item(self::ITEM_STANDING, self::SESSION_A, 5),
        ]);

        $this->assertSame(1, $cart->unitsOf(self::ITEM_SEAT));
        $this->assertSame(5, $cart->unitsOf(self::ITEM_STANDING));
        $this->assertSame(0, $cart->unitsOf(999));
    }

    public function testOnlyAnActiveCartAcceptsChanges(): void
    {
        $this->assertTrue($this->cart(status: CartState::ACTIVE)->acceptsChanges());
        $this->assertFalse($this->cart(status: CartState::CONVERTED)->acceptsChanges());
        $this->assertFalse($this->cart(status: CartState::ABANDONED)->acceptsChanges());
        $this->assertFalse($this->cart(status: CartState::EXPIRED)->acceptsChanges());
    }

    public function testAnUnrecognisedStatusFailsClosed(): void
    {
        // 'banana' was accepted by MySQL; the vocabulary is a proposal, so the
        // status is never validated — only compared, and anything unknown is not
        // the one value that permits writing.
        $this->assertFalse($this->cart(status: 'banana')->acceptsChanges());
    }

    public function testACartWithoutExpiryNeverExpires(): void
    {
        $cart = $this->cart();

        $this->assertFalse($cart->hasExpiry());
        $this->assertFalse($cart->isExpiredAt($this->now->modify('+10 years')));
    }

    public function testACartRejectsAnythingThatIsNotALine(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new Cart(id: 1, sessionId: self::SESSION_A, items: [new \stdClass()]),
            'a cart must reject foreign objects'
        );
    }

    // ── adding a line ────────────────────────────────────────────────────────

    public function testAnItemOfTheCartOwnSessionIsAllowed(): void
    {
        $decision = $this->policy()->addDecision(
            $this->cart(),
            $this->item(self::ITEM_SEAT),
            $this->now
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(CartDecision::ALLOWED, $decision->verdict);
    }

    public function testAnItemOfAnotherSessionIsRefused(): void
    {
        // The headline gap. Nothing in the schema ties inventory_items.session_id
        // to carts.session_id; only this rule does.
        $decision = $this->policy()->addDecision(
            $this->cart(sessionId: self::SESSION_A),
            $this->item(self::ITEM_STANDING, self::SESSION_B, 2),
            $this->now
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(CartDecision::WRONG_SESSION, $decision->verdict);
        $this->assertStringContainsString('session', (string) $decision->reason);
    }

    public function testAnInactiveCartRefusesEverything(): void
    {
        foreach ([CartState::CONVERTED, CartState::ABANDONED, CartState::EXPIRED] as $status) {
            $decision = $this->policy()->addDecision(
                $this->cart(status: $status),
                $this->item(self::ITEM_SEAT),
                $this->now
            );

            $this->assertFalse($decision->isAllowed(), sprintf('status %s must refuse', $status));
            $this->assertSame(CartDecision::CART_NOT_ACTIVE, $decision->verdict);
        }
    }

    public function testAnExpiredCartRefusesItems(): void
    {
        $decision = $this->policy()->addDecision(
            $this->cart(expiresAt: $this->now->modify('-1 second')),
            $this->item(self::ITEM_SEAT),
            $this->now
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(CartDecision::CART_EXPIRED, $decision->verdict);
    }

    public function testIncoherentMoneyIsRefused(): void
    {
        $decision = $this->policy()->addDecision(
            $this->cart(),
            $this->item(self::ITEM_STANDING, self::SESSION_A, 2, 100000, 1),
            $this->now
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(CartDecision::INCOHERENT_MONEY, $decision->verdict);
    }

    public function testAddingAnItemThatIsAlreadyThereRequiresAMerge(): void
    {
        // uq_cart_inventory: a second row for the same item dies with 1062. The
        // correct write is an UPDATE of quantity, and the caller must be told so.
        $decision = $this->policy()->addDecision(
            $this->cart([$this->item(self::ITEM_SEAT)]),
            $this->item(self::ITEM_SEAT),
            $this->now
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertTrue($decision->isMerge());
        $this->assertSame(CartDecision::MERGE_REQUIRED, $decision->verdict);
    }

    public function testTheStateOfTheCartOutranksTheStateOfTheLine(): void
    {
        // An expired cart with a wrong-session line: report the cart, because a
        // dead cart cannot receive anything at all.
        $decision = $this->policy()->addDecision(
            $this->cart(status: CartState::EXPIRED, expiresAt: $this->now->modify('-1 hour')),
            $this->item(self::ITEM_STANDING, self::SESSION_B, 2, 100000, 1),
            $this->now
        );

        $this->assertSame(CartDecision::CART_NOT_ACTIVE, $decision->verdict);
    }

    public function testBelongingOutranksArithmetic(): void
    {
        // Both faults at once: the item is from another session AND its total is
        // wrong. Belonging is reported — a seat at the wrong performance cannot be
        // honoured at any price, a wrong total is at least a fixable number.
        $decision = $this->policy()->addDecision(
            $this->cart(),
            $this->item(self::ITEM_STANDING, self::SESSION_B, 2, 100000, 1),
            $this->now
        );

        $this->assertSame(CartDecision::WRONG_SESSION, $decision->verdict);
    }

    public function testAnExpiredCartOutranksAnInactiveOne(): void
    {
        // Order within the cart's own state: not-active first, then expired.
        $decision = $this->policy()->addDecision(
            $this->cart(status: 'banana', expiresAt: $this->now->modify('-1 hour')),
            $this->item(self::ITEM_SEAT),
            $this->now
        );

        $this->assertSame(CartDecision::CART_NOT_ACTIVE, $decision->verdict);
    }

    // ── lifetime ─────────────────────────────────────────────────────────────

    private function futureSessionStart(): \DateTimeImmutable
    {
        return $this->now->modify('+30 days');
    }

    public function testACartHoldingNothingNeedsNoExpiry(): void
    {
        $decision = $this->policy()->expiryDecision($this->cart(), $this->futureSessionStart());

        $this->assertTrue($decision->isAllowed());
    }

    public function testACartWithHoldsAndNoExpiryIsRefused(): void
    {
        // expires_at is NULLable and nothing relates it to the holds. A cart that
        // never expires keeps offering seats that went back on sale.
        $decision = $this->policy([$this->hold(self::ITEM_SEAT)])
            ->expiryDecision($this->cart(), $this->futureSessionStart());

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(CartDecision::CART_NEVER_EXPIRES, $decision->verdict);
    }

    public function testACartMayNotOutliveThePerformanceItSells(): void
    {
        // Accepted by MySQL: expires_at 2027-01-01 against starts_at 2026-12-01.
        $decision = $this->policy()->expiryDecision(
            $this->cart(expiresAt: $this->now->modify('+60 days')),
            $this->futureSessionStart()
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(CartDecision::OUTLIVES_SESSION, $decision->verdict);
    }

    public function testAnExpiryExactlyAtTheStartOfThePerformanceIsAllowed(): void
    {
        // Boundary: equal is permitted. The last moment of sale is the start.
        $start = $this->futureSessionStart();
        $decision = $this->policy()->expiryDecision($this->cart(expiresAt: $start), $start);

        $this->assertTrue($decision->isAllowed());
    }

    public function testACartMayNotOutliveItsOwnHolds(): void
    {
        // Hold dies in 10 minutes; cart lives an hour. For those 50 minutes the
        // cart looks open while its seats are already back in the pool.
        $decision = $this->policy([$this->hold(self::ITEM_SEAT, 600)])
            ->expiryDecision(
                $this->cart(expiresAt: $this->now->modify('+1 hour')),
                $this->futureSessionStart()
            );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(CartDecision::OUTLIVES_HOLD, $decision->verdict);
    }

    public function testTheEarliestHoldDecidesNotTheLatest(): void
    {
        $decision = $this->policy([
            $this->hold(self::ITEM_SEAT, 1800),
            $this->hold(self::ITEM_STANDING, 300),
        ])->expiryDecision(
            $this->cart(expiresAt: $this->now->modify('+10 minutes')),
            $this->futureSessionStart()
        );

        $this->assertSame(CartDecision::OUTLIVES_HOLD, $decision->verdict);
    }

    public function testACartThatExpiresWithItsEarliestHoldIsAllowed(): void
    {
        $decision = $this->policy([$this->hold(self::ITEM_SEAT, 600)])
            ->expiryDecision(
                $this->cart(expiresAt: $this->now->modify('+600 seconds')),
                $this->futureSessionStart()
            );

        $this->assertTrue($decision->isAllowed());
    }

    public function testAPerformanceThatAlreadyStartedOutranksADeadHold(): void
    {
        // Both faults at once: report the performance. A hold can be retaken; a
        // performance that already happened cannot.
        $past = $this->now->modify('-1 day');
        $decision = $this->policy([$this->hold(self::ITEM_SEAT, 600)])
            ->expiryDecision($this->cart(expiresAt: $this->now->modify('+1 hour')), $past);

        $this->assertSame(CartDecision::OUTLIVES_SESSION, $decision->verdict);
    }

    // ── auditing an existing cart ────────────────────────────────────────────

    public function testAWellFormedCartPassesTheAudit(): void
    {
        $decision = $this->policy()->compositionDecision($this->cart([
            $this->item(self::ITEM_SEAT),
            $this->item(self::ITEM_STANDING, self::SESSION_A, 2),
        ]));

        $this->assertTrue($decision->isAllowed());
    }

    public function testTheAuditFindsTheRowTheSchemaAccepted(): void
    {
        // This is the row that was INSERTed successfully on MySQL 8.4.
        $decision = $this->policy()->compositionDecision($this->cart([
            $this->item(self::ITEM_SEAT),
            $this->item(self::ITEM_STANDING, self::SESSION_B, 2),
        ]));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(CartDecision::WRONG_SESSION, $decision->verdict);
    }

    public function testTheAuditFindsIncoherentMoney(): void
    {
        $decision = $this->policy()->compositionDecision(
            $this->cart([$this->item(self::ITEM_STANDING, self::SESSION_A, 2, 100000, 1)])
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(CartDecision::INCOHERENT_MONEY, $decision->verdict);
    }

    public function testTheAuditReportsStrayItemsAheadOfIncoherentMoney(): void
    {
        $decision = $this->policy()->compositionDecision($this->cart([
            $this->item(self::ITEM_STANDING, self::SESSION_B, 2, 100000, 1),
        ]));

        $this->assertSame(CartDecision::WRONG_SESSION, $decision->verdict);
    }

    public function testAnEmptyCartPassesTheAudit(): void
    {
        $this->assertTrue($this->policy()->compositionDecision($this->cart())->isAllowed());
    }

    public function testThePolicyRefusesForeignHoldObjects(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new CartPolicy([new \stdClass()]),
            'CartPolicy must reject foreign holds'
        );
    }
}
