<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Pricing\Domain\OrderLine;
use Nabilet\Modules\Pricing\Domain\OrderPricing;
use Nabilet\Modules\Pricing\Domain\OrderTotals;
use Nabilet\Modules\Pricing\Domain\PricingContext;
use Nabilet\Modules\Pricing\Domain\PromoCodeDefinition;
use Nabilet\Modules\Pricing\Domain\PromoEvaluation;
use Nabilet\Tests\Support\TestCase;

/**
 * Order pricing is where a ticketing system loses money.
 *
 * These tests are not about arithmetic — the arithmetic is trivial. They are about
 * the failure modes that are invisible until reconciliation: a discount larger
 * than the goods, a percentage applied to lines the code does not cover, a fee
 * that quietly turns the total negative, and float rounding that leaves a kopeck
 * unaccounted for on every thousand orders.
 */
final class OrderPricingTest extends TestCase
{
    private function line(int $priceMinor, int $qty = 1, ?int $eventId = null, ?int $categoryId = null): OrderLine
    {
        return new OrderLine(Money::of($priceMinor), $qty, $eventId, $categoryId);
    }

    private function context(array $lines, string $now = '2026-09-20 12:00:00'): PricingContext
    {
        return PricingContext::of($lines, new \DateTimeImmutable($now));
    }

    // ── the identity ─────────────────────────────────────────────────────────

    public function testTotalIsSubtotalMinusDiscountPlusFee(): void
    {
        $totals = (new OrderPricing())->calculate(
            $this->context([$this->line(100000, 2)]),
            Money::of(5000)
        );

        $this->assertSame(200000, $totals->subtotal()->minorUnits());
        $this->assertSame(0, $totals->discount()->minorUnits());
        $this->assertSame(5000, $totals->fee()->minorUnits());
        $this->assertSame(205000, $totals->total()->minorUnits());
    }

    public function testPayableEqualsTotal(): void
    {
        $totals = (new OrderPricing())->calculate(
            $this->context([$this->line(100000, 2)]),
            Money::of(5000)
        );

        $this->assertSame($totals->total()->minorUnits(), $totals->payable()->minorUnits());
    }

    public function testSumsEveryLine(): void
    {
        $totals = (new OrderPricing())->calculate(
            $this->context([$this->line(150000, 2), $this->line(75000, 3)]),
            Money::zero()
        );

        // 1500.00 * 2 + 750.00 * 3 = 3000.00 + 2250.00
        $this->assertSame(525000, $totals->subtotal()->minorUnits());
    }

    public function testEmptyCartIsZero(): void
    {
        $totals = (new OrderPricing())->calculate($this->context([]), Money::zero());

        $this->assertSame(0, $totals->total()->minorUnits());
        $this->assertTrue($totals->total()->isZero());
    }

    // ── the invariants the schema also enforces ──────────────────────────────

    public function testDiscountIsCappedBySubtotal(): void
    {
        // A 5000 RUB fixed code against a 3000 RUB cart. Without the cap the
        // order stores discount > subtotal and revenue reporting goes negative.
        $promo = new PromoCodeDefinition(
            code: 'BIG',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 500000,
        );

        $totals = (new OrderPricing())->calculate(
            $this->context([$this->line(300000)]),
            Money::zero(),
            $promo
        );

        $this->assertSame(300000, $totals->discount()->minorUnits());
        $this->assertSame(0, $totals->total()->minorUnits());
        $this->assertFalse($totals->total()->isNegative());
    }

    public function testTotalCanNeverBeNegative(): void
    {
        // OrderTotals clamps as its last act, so even a caller that hands it a
        // discount larger than the subtotal gets a zero total, never a negative
        // one — which is what ck_orders_amounts would reject at the database.
        $totals = OrderTotals::of(Money::of(100000), Money::of(999999), Money::zero());

        $this->assertSame(100000, $totals->discount()->minorUnits());
        $this->assertSame(0, $totals->total()->minorUnits());
        $this->assertFalse($totals->total()->isNegative());
    }

    public function testNegativeFeeIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => (new OrderPricing())->calculate(
                $this->context([$this->line(100000)]),
                Money::of(-5000)
            )
        );
    }

    public function testNegativeQuantityIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->line(100000, -1)
        );
    }

    public function testMixedCurrenciesAreRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => PricingContext::of(
                [new OrderLine(Money::of(100000, 'USD'), 1)],
                new \DateTimeImmutable('2026-09-20 12:00:00')
            )
        );
    }

    public function testFeeInAnotherCurrencyIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => (new OrderPricing())->calculate(
                $this->context([$this->line(100000)]),
                Money::of(5000, 'USD')
            )
        );
    }

    // ── percent discount arithmetic ──────────────────────────────────────────

    public function testPercentDiscountIsExactIntegerMath(): void
    {
        // 10.5% of 3333.33 -> 350.00 (333333 * 1050 / 10000 = 35000.0 - let's see:
        // 333333 * 1050 = 349999650; +5000 = 350004650; /10000 = 35000 (int div)
        $promo = PromoCodeDefinition::fromPercentString('10.50', 'HALF');

        $totals = (new OrderPricing())->calculate(
            $this->context([$this->line(333333)]),
            Money::zero(),
            $promo
        );

        $this->assertSame(35000, $totals->discount()->minorUnits());
        $this->assertSame(298333, $totals->total()->minorUnits());
    }

    public function testPercentRoundsHalfUp(): void
    {
        // 5300 * 10 / 10000 ... 10% of 53.00 = 5.30 -> 530 exactly.
        $promo = PromoCodeDefinition::fromPercentString('10', 'TEN');
        $totals = (new OrderPricing())->calculate(
            $this->context([$this->line(5300)]),
            Money::zero(),
            $promo
        );

        $this->assertSame(530, $totals->discount()->minorUnits());
    }

    public function testPercentNeverExceedsSubtotal(): void
    {
        // 100% must wipe the cart out exactly, not overshoot.
        $promo = PromoCodeDefinition::fromPercentString('100', 'FREE');
        $totals = (new OrderPricing())->calculate(
            $this->context([$this->line(123456)]),
            Money::zero(),
            $promo
        );

        $this->assertSame(123456, $totals->discount()->minorUnits());
        $this->assertSame(0, $totals->total()->minorUnits());
    }

    public function testFeeIsAddedAfterDiscountNotBefore(): void
    {
        // A 50% code on 1000.00 plus a 100.00 fee: 500.00 + 100.00 = 600.00.
        // If the fee were discounted too the result would be 550.00.
        $promo = PromoCodeDefinition::fromPercentString('50', 'HALF');
        $totals = (new OrderPricing())->calculate(
            $this->context([$this->line(100000)]),
            Money::of(10000),
            $promo
        );

        $this->assertSame(50000, $totals->discount()->minorUnits());
        $this->assertSame(60000, $totals->total()->minorUnits());
    }

    // ── promo eligibility (ТЗ §86) ───────────────────────────────────────────

    public function testInactiveCodeIsRejected(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'PAUSED',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 10000,
            status: PromoCodeDefinition::STATUS_PAUSED,
        );

        $evaluation = (new OrderPricing())->evaluatePromo($promo, $this->context([$this->line(100000)]));

        $this->assertFalse($evaluation->valid);
        $this->assertSame(PromoEvaluation::REASON_INACTIVE, $evaluation->rejectionReason);
    }

    public function testCodeOutsideItsWindowIsRejected(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'WINDOW',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 10000,
            validFrom: new \DateTimeImmutable('2026-01-01 00:00:00'),
            validUntil: new \DateTimeImmutable('2026-02-01 00:00:00'),
        );

        $late = (new OrderPricing())->evaluatePromo(
            $promo,
            $this->context([$this->line(100000)], '2026-03-01 00:00:00')
        );

        $this->assertFalse($late->valid);
        $this->assertSame(PromoEvaluation::REASON_EXPIRED, $late->rejectionReason);

        $early = (new OrderPricing())->evaluatePromo(
            $promo,
            $this->context([$this->line(100000)], '2025-12-01 00:00:00')
        );

        $this->assertSame(PromoEvaluation::REASON_NOT_STARTED, $early->rejectionReason);
    }

    public function testMinOrderAmountUsesTheWholeCart(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'MIN',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 10000,
            minOrderAmount: 200000,
        );

        // Cart is 150.00 — below the 2000.00 threshold.
        $evaluation = (new OrderPricing())->evaluatePromo($promo, $this->context([$this->line(150000)]));

        $this->assertFalse($evaluation->valid);
        $this->assertSame(PromoEvaluation::REASON_MIN_ORDER_AMOUNT, $evaluation->rejectionReason);
    }

    public function testPerUserLimitIsEnforced(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'ONCE',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 10000,
            perUserLimit: 1,
        );

        $ctx = PricingContext::of(
            [$this->line(100000)],
            new \DateTimeImmutable('2026-09-20 12:00:00'),
            'RUB',
            0,
            1 // already redeemed once
        );

        $evaluation = (new OrderPricing())->evaluatePromo($promo, $ctx);

        $this->assertFalse($evaluation->valid);
        $this->assertSame(PromoEvaluation::REASON_PER_USER_LIMIT, $evaluation->rejectionReason);
    }

    public function testGlobalRedemptionCapIsEnforced(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'LIMITED',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 10000,
            maxRedemptions: 100,
            redemptionsCount: 100,
        );

        $evaluation = (new OrderPricing())->evaluatePromo($promo, $this->context([$this->line(100000)]));

        $this->assertFalse($evaluation->valid);
        $this->assertSame(PromoEvaluation::REASON_MAX_REDEMPTIONS, $evaluation->rejectionReason);
    }

    public function testFirstPurchaseCodeRejectsReturningCustomers(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'WELCOME',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 10000,
            scope: PromoCodeDefinition::SCOPE_FIRST_PURCHASE,
        );

        $returning = PricingContext::of(
            [$this->line(100000)],
            new \DateTimeImmutable('2026-09-20 12:00:00'),
            'RUB',
            3 // three prior orders
        );

        $evaluation = (new OrderPricing())->evaluatePromo($promo, $returning);

        $this->assertFalse($evaluation->valid);
        $this->assertSame(PromoEvaluation::REASON_NOT_FIRST_PURCHASE, $evaluation->rejectionReason);

        $newcomer = PricingContext::of(
            [$this->line(100000)],
            new \DateTimeImmutable('2026-09-20 12:00:00'),
            'RUB',
            0
        );

        $this->assertTrue((new OrderPricing())->evaluatePromo($promo, $newcomer)->valid);
    }

    // ── scoping: the over-discount bug ───────────────────────────────────────

    public function testEventScopedCodeDiscountsOnlyThatEvent(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'EVENT50',
            discountType: PromoCodeDefinition::TYPE_PERCENT,
            valuePercentBasisPoints: 5000, // 50%
            scope: PromoCodeDefinition::SCOPE_EVENT,
            eventId: 42,
        );

        $ctx = $this->context([
            $this->line(100000, 1, 42),   // 1000.00 in the promo event
            $this->line(900000, 1, 7),    // 9000.00 elsewhere
        ]);

        $totals = (new OrderPricing())->calculate($ctx, Money::zero(), $promo);

        // 50% of 1000.00 only. Discounting everything would have taken 5000.00.
        $this->assertSame(50000, $totals->discount()->minorUnits());
        $this->assertSame(1000000, $totals->subtotal()->minorUnits());
        $this->assertSame(950000, $totals->total()->minorUnits());
    }

    public function testCategoryScopedCodeIgnoresOtherCategories(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'CAT',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 50000,
            scope: PromoCodeDefinition::SCOPE_CATEGORY,
            eventCategoryId: 5,
        );

        $ctx = $this->context([
            $this->line(200000, 1, null, 5),
            $this->line(100000, 1, null, 9),
        ]);

        $totals = (new OrderPricing())->calculate($ctx, Money::zero(), $promo);

        $this->assertSame(50000, $totals->discount()->minorUnits());
    }

    public function testScopedCodeWithNoMatchingLineIsRejected(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'EVENT',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 50000,
            scope: PromoCodeDefinition::SCOPE_EVENT,
            eventId: 42,
        );

        $evaluation = (new OrderPricing())->evaluatePromo(
            $promo,
            $this->context([$this->line(100000, 1, 7)])
        );

        $this->assertFalse($evaluation->valid);
        $this->assertSame(PromoEvaluation::REASON_SCOPE_EVENT, $evaluation->rejectionReason);
    }

    public function testRejectedPromoStillPricesTheCart(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'DEAD',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 50000,
            status: PromoCodeDefinition::STATUS_PAUSED,
        );

        $totals = (new OrderPricing())->calculate(
            $this->context([$this->line(100000)]),
            Money::of(10000),
            $promo
        );

        // The customer can still check out; they just get no discount.
        $this->assertSame(0, $totals->discount()->minorUnits());
        $this->assertSame(110000, $totals->total()->minorUnits());
        $this->assertNotNull($totals->promo());
        $this->assertFalse($totals->promo()->valid);
    }

    // ── percent parsing ──────────────────────────────────────────────────────

    public function testPercentStringBecomesBasisPointsExactly(): void
    {
        $this->assertSame(1050, PromoCodeDefinition::fromPercentString('10.50', 'X')->valuePercentBasisPoints);
        $this->assertSame(1000, PromoCodeDefinition::fromPercentString('10', 'X')->valuePercentBasisPoints);
        $this->assertSame(1000, PromoCodeDefinition::fromPercentString('10.00', 'X')->valuePercentBasisPoints);
        $this->assertSame(5, PromoCodeDefinition::fromPercentString('0.05', 'X')->valuePercentBasisPoints);
        $this->assertSame(10000, PromoCodeDefinition::fromPercentString('100.00', 'X')->valuePercentBasisPoints);
    }

    public function testOutOfRangePercentIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new PromoCodeDefinition(code: 'BAD', valuePercentBasisPoints: 20000)
        );
    }

    public function testZeroValuePromoIsRejectedAtConstruction(): void
    {
        // A code that accepts and then discounts nothing is a support ticket.
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => new PromoCodeDefinition(code: 'ZERO', discountType: PromoCodeDefinition::TYPE_FIXED, valueAmount: 0)
        );
    }

    // ── the API shape ────────────────────────────────────────────────────────

    public function testSerializesToTheMoneyTotalsContract(): void
    {
        $promo = PromoCodeDefinition::fromPercentString('10', 'TEN');
        $totals = (new OrderPricing())->calculate(
            $this->context([$this->line(100000)]),
            Money::of(5000),
            $promo
        );

        $json = $totals->jsonSerialize();

        $this->assertSame(100000, $json['subtotal_amount']);
        $this->assertSame(10000, $json['discount_amount']);
        $this->assertSame(5000, $json['fee_amount']);
        $this->assertSame(95000, $json['total_amount']);
        $this->assertSame('RUB', $json['currency']);
    }

    public function testPromoEvaluationSerializesWithReason(): void
    {
        $promo = new PromoCodeDefinition(
            code: 'OLD',
            discountType: PromoCodeDefinition::TYPE_FIXED,
            valueAmount: 10000,
            validUntil: new \DateTimeImmutable('2026-01-01 00:00:00'),
        );

        $json = (new OrderPricing())->evaluatePromo($promo, $this->context([$this->line(100000)]))
            ->jsonSerialize();

        $this->assertFalse($json['valid']);
        $this->assertSame(0, $json['discount_amount']);
        $this->assertSame(PromoEvaluation::REASON_EXPIRED, $json['rejection_reason']);
    }
}
