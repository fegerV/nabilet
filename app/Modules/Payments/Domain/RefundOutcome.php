<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use Nabilet\Core\Support\Money;

/**
 * The consequence of one refund for its order.
 *
 * Carries the resulting money, not just a status, because the ORDER's next status
 * is a function of how much has come back — and computing that at the call site is
 * exactly how an order ends up `refunded` while one of its four seats is still
 * held and still saleable.
 */
final class RefundOutcome
{
    private function __construct(
        private readonly bool $allowed,
        private readonly ?string $reason,
        private readonly ?string $orderStatus,
        private readonly Money $refundedTotal,
        private readonly Money $remaining,
    ) {
    }

    public static function allowed(string $orderStatus, Money $refundedTotal, Money $remaining): self
    {
        return new self(true, null, $orderStatus, $refundedTotal, $remaining);
    }

    public static function refused(string $reason, Money $refundedTotal, Money $remaining): self
    {
        return new self(false, $reason, null, $refundedTotal, $remaining);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function orderStatus(): ?string
    {
        return $this->orderStatus;
    }

    /** How much will have been returned once this refund lands. */
    public function refundedTotal(): Money
    {
        return $this->refundedTotal;
    }

    /** How much of the payment is still with us. Never negative. */
    public function remaining(): Money
    {
        return $this->remaining;
    }

    public function isFullRefund(): bool
    {
        return $this->remaining->isZero();
    }
}
