<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\Money;
use Nabilet\Modules\Pricing\Domain\PricingContext;
use Nabilet\Modules\Pricing\Domain\PromoCodeDefinition as Def;
use Nabilet\Modules\Pricing\Domain\PromoEvaluation;
use Nabilet\Modules\Pricing\Domain\PromoEvaluator;
use Nabilet\Modules\Pricing\Domain\OrderLine;
use Nabilet\Tests\Support\TestCase;

/**
 * Мост «checkout → PromoEvaluator».
 *
 * CartCheckoutService больше не бросает PROMO_CODE_NOT_SUPPORTED: он вызывает
 * PromoCodeService::evaluateForCart(), который строит PricingContext из строк
 * корзины и отдаёт вердикт движка цен. Сервис требует БД (Eloquent), поэтому
 * здесь проверяется ровно то, на что опирается checkout: для каждой причины
 * отказа движок возвращает `valid:false` со стабильным rejection_reason — и
 * checkout превращает её в 422 PROMO_CODE_REJECTED, не создавая заказ; для
 * успеха — сумма скидки в копейках, которую checkout пишет в
 * orders.discount_amount. Двойной учёт скидки в двух местах был бы вторым
 * источником правды, поэтому проверка идёт через тот же evaluator, что и
 * POST /promo-codes/validate.
 */
final class PromoEvaluatorCheckoutBridgeTest extends TestCase
{
    private PromoEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new PromoEvaluator();
    }

    /** @param list<OrderLine> $lines */
    private function context(array $lines, int $priorOrders = 0, int $userRedemptions = 0): PricingContext
    {
        return PricingContext::of(
            lines: $lines,
            currency: 'RUB',
            now: new \DateTimeImmutable('2026-10-09 12:00:00'),
            customerPriorOrderCount: $priorOrders,
            customerRedemptionsOfCode: $userRedemptions,
        );
    }

    private function line(int $priceMinor, int $qty = 1, ?int $eventId = null): OrderLine
    {
        return new OrderLine(Money::of($priceMinor, 'RUB'), $qty, $eventId);
    }

    // ── успех: скидка, которую checkout запишет в заказ ─────────────────────

    public function testPercentCodeAcceptedWithExactDiscount(): void
    {
        $promo = new Def(code: 'SUMMER', discountType: Def::TYPE_PERCENT, valuePercentBasisPoints: 1000);

        $result = $this->evaluator->evaluate($promo, $this->context([$this->line(100000, 2)]));

        $this->assertTrue($result->valid);
        // 10% от 200 000 = 20 000 копеек — ровно это уйдёт в discount_amount.
        $this->assertSame(20000, $result->discount->minorUnits());
        $this->assertNull($result->rejectionReason);
    }

    public function testFixedDiscountCappedAtApplicableSubtotal(): void
    {
        $promo = new Def(code: 'BIG', discountType: Def::TYPE_FIXED, valueAmount: 500000);

        $result = $this->evaluator->evaluate($promo, $this->context([$this->line(300000)]));

        $this->assertTrue($result->valid);
        // Cap #1: скидка не может превысить товар — иначе total уходит в минус.
        $this->assertSame(300000, $result->discount->minorUnits());
    }

    // ── отказы: каждая даёт checkout стабильный reason без создания заказа ──

    public function testExpiredWindowRejects(): void
    {
        $promo = new Def(
            code: 'OLD',
            valuePercentBasisPoints: 1000,
            validUntil: new \DateTimeImmutable('2026-10-01 00:00:00'),
        );

        $result = $this->evaluator->evaluate($promo, $this->context([$this->line(100000)]));

        $this->assertFalse($result->valid);
        $this->assertSame(PromoEvaluation::REASON_EXPIRED, $result->rejectionReason);
    }

    public function testPausedStatusRejects(): void
    {
        $promo = new Def(code: 'PAUSED', valuePercentBasisPoints: 1000, status: Def::STATUS_PAUSED);

        $result = $this->evaluator->evaluate($promo, $this->context([$this->line(100000)]));

        $this->assertFalse($result->valid);
        $this->assertSame(PromoEvaluation::REASON_INACTIVE, $result->rejectionReason);
    }

    public function testGlobalLimitExhaustedRejects(): void
    {
        $promo = new Def(code: 'LIMITED', valuePercentBasisPoints: 1000, maxRedemptions: 5, redemptionsCount: 5);

        $result = $this->evaluator->evaluate($promo, $this->context([$this->line(100000)]));

        $this->assertFalse($result->valid);
        $this->assertSame(PromoEvaluation::REASON_MAX_REDEMPTIONS, $result->rejectionReason);
    }

    public function testPerUserLimitRejects(): void
    {
        $promo = new Def(code: 'ONCE', valuePercentBasisPoints: 1000, perUserLimit: 1);

        $result = $this->evaluator->evaluate($promo, $this->context([$this->line(100000)], userRedemptions: 1));

        $this->assertFalse($result->valid);
        $this->assertSame(PromoEvaluation::REASON_PER_USER_LIMIT, $result->rejectionReason);
    }

    public function testFirstPurchaseScopeRejectsReturningCustomer(): void
    {
        $promo = new Def(code: 'HELLO', valuePercentBasisPoints: 1000, scope: Def::SCOPE_FIRST_PURCHASE);

        $result = $this->evaluator->evaluate($promo, $this->context([$this->line(100000)], priorOrders: 1));

        $this->assertFalse($result->valid);
        $this->assertSame(PromoEvaluation::REASON_NOT_FIRST_PURCHASE, $result->rejectionReason);
    }

    public function testMinOrderAmountRejectsSmallCart(): void
    {
        $promo = new Def(code: 'BIGSPEND', valuePercentBasisPoints: 1000, minOrderAmount: 100000);

        $result = $this->evaluator->evaluate($promo, $this->context([$this->line(50000)]));

        $this->assertFalse($result->valid);
        $this->assertSame(PromoEvaluation::REASON_MIN_ORDER_AMOUNT, $result->rejectionReason);
    }

    public function testCurrencyMismatchRejects(): void
    {
        $promo = new Def(code: 'EUR', valuePercentBasisPoints: 1000, currency: 'EUR');

        $result = $this->evaluator->evaluate($promo, $this->context([$this->line(100000)]));

        $this->assertFalse($result->valid);
        $this->assertSame(PromoEvaluation::REASON_CURRENCY_MISMATCH, $result->rejectionReason);
    }

    public function testEventScopedCodeOnlyDiscountsMatchingLines(): void
    {
        // Классический over-discount: дешёвый билет промо-события + дорогой
        // сторонний. Скидка 50% обязана считаться только по применимой строке.
        $promo = new Def(code: 'SHOW', valuePercentBasisPoints: 5000, scope: Def::SCOPE_EVENT, eventId: 7);

        $result = $this->evaluator->evaluate($promo, $this->context([
            $this->line(20000, 1, eventId: 7),
            $this->line(180000, 1, eventId: 8),
        ]));

        $this->assertTrue($result->valid);
        $this->assertSame(10000, $result->discount->minorUnits());
    }

    public function testJsonShapeIsWhatValidateEndpointAndCheckoutConsume(): void
    {
        $promo = new Def(code: 'SHAPE', valuePercentBasisPoints: 1000);

        $json = $this->evaluator->evaluate($promo, $this->context([$this->line(100000)]))->jsonSerialize();

        $this->assertSame(['valid', 'discount_amount', 'rejection_reason'], array_keys($json));
        $this->assertTrue($json['valid']);
        // 10% (1000 б.п.) от 100 000 копеек = 10 000.
        $this->assertSame(10000, $json['discount_amount']);
    }
}
