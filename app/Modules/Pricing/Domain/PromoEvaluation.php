<?php

declare(strict_types=1);

namespace Nabilet\Modules\Pricing\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;

/**
 * The verdict on a promo code: accepted with a discount, or rejected with a
 * machine-readable reason.
 *
 * A rejection is NOT an exception. "This code does not apply" is a normal,
 * expected answer that the API returns as `valid: false` with a
 * `rejection_reason` — the customer typed a code that has expired, and that is a
 * 200 with an explanation, not a 500. Throwing here would also let one bad code
 * abort pricing for an otherwise perfectly good cart.
 *
 * Exceptions are reserved for programmer error: a discount in the wrong currency,
 * a negative amount — things that cannot happen from customer input.
 */
final class PromoEvaluation implements \JsonSerializable
{
    public const REASON_CURRENCY_MISMATCH = 'currency_mismatch';
    public const REASON_INACTIVE = 'inactive';
    public const REASON_NOT_STARTED = 'not_started';
    public const REASON_EXPIRED = 'expired';
    public const REASON_MAX_REDEMPTIONS = 'max_redemptions';
    public const REASON_PER_USER_LIMIT = 'per_user_limit';
    public const REASON_NOT_FIRST_PURCHASE = 'not_first_purchase';
    public const REASON_SCOPE_EVENT = 'scope_event';
    public const REASON_SCOPE_CATEGORY = 'scope_category';
    public const REASON_MIN_ORDER_AMOUNT = 'min_order_amount';

    private function __construct(
        public readonly bool $valid,
        public readonly Money $discount,
        public readonly ?string $rejectionReason,
    ) {
    }

    public static function accepted(Money $discount): self
    {
        if ($discount->isNegative()) {
            throw new DomainRuleViolation('A discount cannot be negative.', 'INVALID_DISCOUNT');
        }

        return new self(true, $discount, null);
    }

    public static function rejected(string $reason, string $currency = 'RUB'): self
    {
        return new self(false, Money::zero($currency), $reason);
    }

    /** @return array{valid: bool, discount_amount: int, rejection_reason: string|null} */
    public function jsonSerialize(): array
    {
        return [
            'valid' => $this->valid,
            'discount_amount' => $this->discount->minorUnits(),
            'rejection_reason' => $this->rejectionReason,
        ];
    }
}
