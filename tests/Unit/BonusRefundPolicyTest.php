<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Loyalty\Domain\BonusRefundPolicy;
use Nabilet\Tests\Support\TestCase;

/** Границы пропорционального возврата бонусов. */
final class BonusRefundPolicyTest extends TestCase
{
    public function testHalfOfTheMoneyReturnsHalfOfTheBonuses(): void
    {
        $this->assertSame(15_000, BonusRefundPolicy::portion(30_000, 70_000, 35_000, 0));
    }

    public function testAlreadyReturnedBonusesAreSubtracted(): void
    {
        $this->assertSame(7_500, BonusRefundPolicy::portion(30_000, 70_000, 52_500, 15_000));
    }

    public function testTheFinalRefundSettlesTheRemainder(): void
    {
        $this->assertSame(7_500, BonusRefundPolicy::portion(30_000, 70_000, 70_000, 22_500));
    }

    public function testRepeatedRefundDoesNotReturnAnything(): void
    {
        $this->assertSame(0, BonusRefundPolicy::portion(30_000, 70_000, 35_000, 15_000));
    }

    public function testRoundingIsDownAndNeverExceedsTheSpentAmount(): void
    {
        $this->assertSame(3, BonusRefundPolicy::portion(10, 3, 1, 0));
        $this->assertSame(0, BonusRefundPolicy::portion(10, 0, 5, 0));
        $this->assertSame(0, BonusRefundPolicy::portion(0, 70_000, 35_000, 0));
        $this->assertSame(10, BonusRefundPolicy::portion(10, 10, 999, 0));
    }
}
