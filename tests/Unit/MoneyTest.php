<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use Nabilet\Tests\Support\TestCase;

/**
 * Money correctness is not cosmetic: the ticketing flow adds ticket prices,
 * applies percentage promo codes, adds a service fee and splits refunds per
 * ticket. Float arithmetic loses fractions of a kopeck on every step, and the
 * total then fails to reconcile against the payment provider.
 */
final class MoneyTest extends TestCase
{
    public function testStoresIntegerMinorUnits(): void
    {
        $price = Money::of(5000);

        $this->assertSame(5000, $price->minorUnits());
        $this->assertSame('RUB', $price->currency());
        $this->assertSame('50.00', $price->toDecimal());
    }

    public function testParsesDecimalWithoutFloatArithmetic(): void
    {
        // 0.1 + 0.2 in binary float is famously 0.30000000000000004
        $a = Money::fromDecimal('0.10');
        $b = Money::fromDecimal('0.20');

        $this->assertSame(10, $a->minorUnits());
        $this->assertSame(20, $b->minorUnits());
        $this->assertSame(30, $a->plus($b)->minorUnits());
        $this->assertSame('0.30', $a->plus($b)->toDecimal());
    }

    public function testParsesDecimalWithCommaAndSpaces(): void
    {
        $this->assertSame(530000, Money::fromDecimal('5 300,00')->minorUnits());
    }

    public function testParsesNegativeAmounts(): void
    {
        $this->assertSame(-1250, Money::fromDecimal('-12.50')->minorUnits());
    }

    public function testPadsShortFraction(): void
    {
        // "5000.5" must mean 500050 minor units, not 50005
        $this->assertSame(500050, Money::fromDecimal('5000.5')->minorUnits());
    }

    public function testRejectsUnparsableAmount(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            static fn () => Money::fromDecimal('not money')
        );
    }

    /**
     * The invariant that matters for revenue splits: the parts must always sum
     * back to the whole, with no lost or invented minor unit.
     */
    public function testAllocateNeverLosesOrInventsMinorUnits(): void
    {
        $total = Money::of(1000);
        $shares = $total->allocate(3);

        $this->assertCount(3, $shares);
        $this->assertSame(334, $shares[0]->minorUnits());
        $this->assertSame(333, $shares[1]->minorUnits());
        $this->assertSame(333, $shares[2]->minorUnits());

        $sum = Money::zero();
        foreach ($shares as $share) {
            $sum = $sum->plus($share);
        }
        $this->assertTrue($sum->equals($total));
    }

    public function testAllocateHoldsInvariantAcrossManyDivisors(): void
    {
        for ($total = 1; $total <= 200; $total++) {
            for ($parts = 1; $parts <= 7; $parts++) {
                $money = Money::of($total);
                $sum = Money::zero();
                foreach ($money->allocate($parts) as $share) {
                    $sum = $sum->plus($share);
                }

                if (! $sum->equals($money)) {
                    $this->fail(sprintf('allocate(%d) of %d did not sum back.', $parts, $total));
                }
            }
        }

        $this->assertTrue(true);
    }

    public function testAllocateRejectsNonPositiveParts(): void
    {
        $this->assertThrows(DomainRuleViolation::class, static fn () => Money::of(100)->allocate(0));
    }

    public function testPercentageDiscountRoundsHalfUp(): void
    {
        // 10% of 5300 minor units is exactly 530
        $this->assertSame(530, Money::of(5300)->percentage(10)->minorUnits());
        // 10% of 5005 is 500.5 -> rounds to 501, not truncated to 500
        $this->assertSame(501, Money::of(5005)->percentage(10)->minorUnits());
    }

    public function testOrderTotalsComposeCorrectly(): void
    {
        // Two tickets at 5000 plus a 300 service fee
        $tickets = Money::of(5000)->times(2);
        $total = $tickets->plus(Money::of(300));

        $this->assertSame(10300, $total->minorUnits());
        $this->assertSame('103.00', $total->toDecimal());
    }

    public function testRefusesToMixCurrencies(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            static fn () => Money::of(100, 'RUB')->plus(Money::of(100, 'USD'))
        );
    }

    public function testRejectsInvalidCurrencyCode(): void
    {
        $this->assertThrows(DomainRuleViolation::class, static fn () => Money::of(100, 'RU'));
    }

    public function testComparisonAndSignHelpers(): void
    {
        $this->assertTrue(Money::of(5000)->greaterThan(Money::of(4999)));
        $this->assertTrue(Money::of(0)->isZero());
        $this->assertTrue(Money::of(1)->isPositive());
        $this->assertTrue(Money::of(-1)->isNegative());
        $this->assertTrue(Money::of(100)->equals(Money::of(100)));
        $this->assertFalse(Money::of(100, 'RUB')->equals(Money::of(100, 'USD')));
    }

    public function testNegateProducesRefundAmount(): void
    {
        $this->assertSame(-5300, Money::of(5300)->negate()->minorUnits());
    }

    public function testSerialisesForApi(): void
    {
        $this->assertSame(
            ['amount' => 5300, 'currency' => 'RUB', 'decimal' => '53.00'],
            Money::of(5300)->jsonSerialize()
        );
    }

    public function testTimesRejectsNegativeQuantity(): void
    {
        $this->assertThrows(DomainRuleViolation::class, static fn () => Money::of(100)->times(-1));
    }
}
