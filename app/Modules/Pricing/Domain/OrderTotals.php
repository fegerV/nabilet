<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;

/**
 * The four money columns of an order: subtotal, discount, fee, total.
 *
 * This object is the single place where `total = subtotal - discount + fee` is
 * decided. Everything else — the API response, the DB row, the payment capture,
 * the refund calculation — reads it from here. If the identity were computed in
 * three places, they would eventually disagree, and the disagreement would surface
 * as a payment that does not match the order.
 *
 * The invariants mirror `ck_orders_amounts` in the schema, which is the last line
 * of defence:
 *   subtotal_amount >= 0 AND discount_amount >= 0
 *   AND fee_amount >= 0 AND total_amount >= 0
 * A value object that cannot hold an invalid state means the CHECK can only ever
 * fire on a bug, never on data.
 */
final class OrderTotals implements \JsonSerializable
{
    private function __construct(
        private readonly Money $subtotal,
        private readonly Money $discount,
        private readonly Money $fee,
        private readonly Money $total,
        private readonly ?PromoEvaluation $promo,
    ) {
    }

    public static function of(Money $subtotal, Money $discount, Money $fee, ?PromoEvaluation $promo = null): self
    {
        if ($subtotal->isNegative()) {
            throw new DomainRuleViolation('A subtotal cannot be negative.', 'INVALID_TOTALS');
        }

        if ($discount->isNegative()) {
            throw new DomainRuleViolation('A discount cannot be negative.', 'INVALID_TOTALS');
        }

        if ($fee->isNegative()) {
            // The schema has ck_orders_amounts (fee_amount >= 0). A negative fee
            // is a discount wearing a costume; route it through the discount.
            throw new DomainRuleViolation(
                'A fee cannot be negative. Model a reduction as a discount, not as a negative fee.',
                'INVALID_TOTALS'
            );
        }

        if ($discount->currency() !== $subtotal->currency() || $fee->currency() !== $subtotal->currency()) {
            throw new DomainRuleViolation(
                sprintf(
                    'Cannot combine totals across currencies (%s / %s / %s).',
                    $subtotal->currency(),
                    $discount->currency(),
                    $fee->currency()
                ),
                'CURRENCY_MISMATCH'
            );
        }

        // Final clamp. The evaluator already caps a discount by the lines it
        // applies to, but this is the guarantee that `subtotal - discount` can
        // never go negative no matter which caller assembled the parts.
        $effectiveDiscount = $discount->greaterThan($subtotal) ? $subtotal : $discount;

        $total = $subtotal->minus($effectiveDiscount)->plus($fee);

        return new self($subtotal, $effectiveDiscount, $fee, $total, $promo);
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function discount(): Money
    {
        return $this->discount;
    }

    public function fee(): Money
    {
        return $this->fee;
    }

    public function total(): Money
    {
        return $this->total;
    }

    public function promo(): ?PromoEvaluation
    {
        return $this->promo;
    }

    /**
     * The amount the customer actually pays. Identical to `total()` — named
     * separately because "total" is the stored column and "payable" is what the
     * payment provider must be asked to charge; conflating them is how a system
     * ends up charging the subtotal.
     */
    public function payable(): Money
    {
        return $this->total;
    }

    /** Matches the MoneyTotals schema in the OpenAPI contract. */
    public function jsonSerialize(): array
    {
        return [
            'subtotal_amount' => $this->subtotal->minorUnits(),
            'discount_amount' => $this->discount->minorUnits(),
            'fee_amount' => $this->fee->minorUnits(),
            'total_amount' => $this->total->minorUnits(),
            'currency' => $this->total->currency(),
        ];
    }
}
