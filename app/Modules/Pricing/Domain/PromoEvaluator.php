<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;

/**
 * Decides whether a promo code applies to a cart, and for how much (ТЗ §86).
 *
 * ORDER OF CHECKS MATTERS, and is deliberate: the cheap, always-applicable
 * conditions (status, window, counters) run before the expensive per-line
 * matching. More importantly, the checks are ordered so the reason returned to
 * the customer is the most useful one — telling someone their code is expired is
 * more actionable than telling them it does not match the event.
 *
 * TWO CAPS, BOTH ESSENTIAL:
 *  1. A discount is capped by the subtotal of the lines it actually applies to.
 *     A 5000 RUB fixed code against a 3000 RUB cart must discount 3000. Without
 *     the cap `orders.discount_amount` exceeds `subtotal_amount`, revenue
 *     reporting goes negative, and a refund of "the discount" returns money that
 *     was never charged.
 *  2. A percent discount is computed on the APPLICABLE lines only. Scoping a code
 *     to an event and then discounting the whole cart is the classic over-discount
 *     bug: buy one cheap ticket from the promo event plus nine expensive ones,
 *     get the percentage off everything.
 */
final class PromoEvaluator
{
    public function evaluate(PromoCodeDefinition $promo, PricingContext $ctx): PromoEvaluation
    {
        $currency = $ctx->currency;

        if ($promo->currency !== $currency) {
            return PromoEvaluation::rejected(PromoEvaluation::REASON_CURRENCY_MISMATCH, $currency);
        }

        if (! $promo->isActive()) {
            return PromoEvaluation::rejected(PromoEvaluation::REASON_INACTIVE, $currency);
        }

        if ($promo->validFrom !== null && $ctx->now < $promo->validFrom) {
            return PromoEvaluation::rejected(PromoEvaluation::REASON_NOT_STARTED, $currency);
        }

        if ($promo->validUntil !== null && $ctx->now > $promo->validUntil) {
            return PromoEvaluation::rejected(PromoEvaluation::REASON_EXPIRED, $currency);
        }

        if ($promo->maxRedemptions !== null && $promo->redemptionsCount >= $promo->maxRedemptions) {
            return PromoEvaluation::rejected(PromoEvaluation::REASON_MAX_REDEMPTIONS, $currency);
        }

        if ($ctx->customerRedemptionsOfCode >= $promo->perUserLimit) {
            return PromoEvaluation::rejected(PromoEvaluation::REASON_PER_USER_LIMIT, $currency);
        }

        $applicable = $this->applicableLines($promo, $ctx);

        if ($applicable === null) {
            // Scope excluded everything; the reason is set by applicableLines().
            return PromoEvaluation::rejected($this->scopeRejection($promo), $currency);
        }

        // "min order amount" is compared against the WHOLE order, not the
        // applicable lines. ТЗ §86 says "order amount", and the validate endpoint
        // takes `order_amount`. Comparing against the scoped subtotal instead
        // would let a code demand 5000 RUB and be satisfied by a 500 RUB
        // qualifying line inside a 400 RUB cart.
        if ($ctx->subtotal()->minorUnits() < $promo->minOrderAmount) {
            return PromoEvaluation::rejected(PromoEvaluation::REASON_MIN_ORDER_AMOUNT, $currency);
        }

        $applicableSubtotal = $this->sum($applicable, $currency);

        $discount = match ($promo->discountType) {
            PromoCodeDefinition::TYPE_FIXED => $this->fixedDiscount($promo, $applicableSubtotal),
            PromoCodeDefinition::TYPE_PERCENT => $this->percentDiscount($promo, $applicableSubtotal),
            default => throw new DomainRuleViolation(
                sprintf('Unknown discount type "%s".', $promo->discountType),
                'INVALID_PROMO_TYPE'
            ),
        };

        // Cap #1. Also guards an empty applicable set (subtotal 0 -> discount 0).
        if ($discount->greaterThan($applicableSubtotal)) {
            $discount = $applicableSubtotal;
        }

        return PromoEvaluation::accepted($discount);
    }

    /**
     * @return list<OrderLine>|null the eligible lines, or null when the scope
     *                              excludes the whole cart
     */
    private function applicableLines(PromoCodeDefinition $promo, PricingContext $ctx): ?array
    {
        switch ($promo->scope) {
            case PromoCodeDefinition::SCOPE_ALL:
                return $ctx->lines;

            case PromoCodeDefinition::SCOPE_EVENT:
                $lines = $ctx->linesMatchingEvent($promo->eventId);

                return $lines === [] ? null : $lines;

            case PromoCodeDefinition::SCOPE_CATEGORY:
                $lines = $ctx->linesMatchingCategory($promo->eventCategoryId);

                return $lines === [] ? null : $lines;

            case PromoCodeDefinition::SCOPE_FIRST_PURCHASE:
                if ($ctx->customerPriorOrderCount > 0) {
                    return null;
                }

                return $ctx->lines;

            default:
                throw new DomainRuleViolation(
                    sprintf('Unknown promo scope "%s".', $promo->scope),
                    'INVALID_PROMO_SCOPE'
                );
        }
    }

    private function scopeRejection(PromoCodeDefinition $promo): string
    {
        return match ($promo->scope) {
            PromoCodeDefinition::SCOPE_EVENT => PromoEvaluation::REASON_SCOPE_EVENT,
            PromoCodeDefinition::SCOPE_CATEGORY => PromoEvaluation::REASON_SCOPE_CATEGORY,
            PromoCodeDefinition::SCOPE_FIRST_PURCHASE => PromoEvaluation::REASON_NOT_FIRST_PURCHASE,
            default => PromoEvaluation::REASON_SCOPE_EVENT,
        };
    }

    private function fixedDiscount(PromoCodeDefinition $promo, Money $applicableSubtotal): Money
    {
        $amount = Money::of($promo->valueAmount, $applicableSubtotal->currency());

        return $amount->greaterThan($applicableSubtotal) ? $applicableSubtotal : $amount;
    }

    /**
     * Integer-only percentage: discount = subtotal * basisPoints / 10000, rounded
     * half-up. 10000 basis points == 100%.
     *
     * Written as `intdiv($a * $bp + 5000, 10000)` rather than `round($a * $bp /
     * 10000)` so no float ever touches the money — at a 10^12 minor-unit subtotal
     * the float product is already beyond exact representation.
     */
    private function percentDiscount(PromoCodeDefinition $promo, Money $applicableSubtotal): Money
    {
        $bp = $promo->valuePercentBasisPoints;
        $units = $applicableSubtotal->minorUnits();

        if ($bp <= 0 || $units <= 0) {
            return Money::zero($applicableSubtotal->currency());
        }

        $discounted = intdiv($units * $bp + 5000, 10000);

        return Money::of($discounted, $applicableSubtotal->currency());
    }

    /**
     * @param list<OrderLine> $lines
     */
    private function sum(array $lines, string $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($lines as $line) {
            $total = $total->plus($line->subtotal());
        }

        return $total;
    }
}
