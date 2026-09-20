<?php

declare(strict_types=1);

namespace Nabilet\Modules\Pricing\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;

/**
 * Turns cart lines into order totals.
 *
 * WHY THE FEE IS AN ARGUMENT AND NOT A RULE
 *   `orders.fee_amount` exists in the schema, but neither the ТЗ nor the OpenAPI
 *   contract defines how the service fee is computed — it is per-organization
 *   configuration. Inventing a formula here would bake an invented business rule
 *   into the one place everything else trusts. So the caller supplies the fee and
 *   this class owns the arithmetic and the invariants, which is the part that
 *   actually has to be right.
 *
 * WHEN THE PROMO IS REJECTED, THE CART IS STILL PRICED.
 *   A rejected code yields a zero discount and a `PromoEvaluation` carrying the
 *   reason — it does not abort. Otherwise a customer who mistyped a code could
 *   not check out at all.
 */
final class OrderPricing
{
    public function __construct(private readonly PromoEvaluator $evaluator = new PromoEvaluator())
    {
    }

    public function calculate(PricingContext $ctx, Money $fee, ?PromoCodeDefinition $promo = null): OrderTotals
    {
        if ($fee->currency() !== $ctx->currency) {
            throw new DomainRuleViolation(
                sprintf(
                    'The fee is in %s but the cart is in %s.',
                    $fee->currency(),
                    $ctx->currency
                ),
                'CURRENCY_MISMATCH'
            );
        }

        $evaluation = $promo === null
            ? null
            : $this->evaluator->evaluate($promo, $ctx);

        $discount = $evaluation === null || ! $evaluation->valid
            ? Money::zero($ctx->currency)
            : $evaluation->discount;

        return OrderTotals::of($ctx->subtotal(), $discount, $fee, $evaluation);
    }

    /**
     * Evaluate a code without pricing the order — the `/promo-codes/validate`
     * endpoint answers this, and the customer sees the reason before committing.
     */
    public function evaluatePromo(PromoCodeDefinition $promo, PricingContext $ctx): PromoEvaluation
    {
        return $this->evaluator->evaluate($promo, $ctx);
    }
}
