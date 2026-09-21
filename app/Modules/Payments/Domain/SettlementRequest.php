<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\Money;
use App\Modules\Payments\StateMachines\PaymentStateMachine;

/**
 * One provider callback about one payment, with the order state it would change.
 *
 * The provider's status arrives already translated into OUR vocabulary
 * (`PaymentStateMachine`), never the provider's own spelling. The translation
 * lives at the provider adapter: it is the adapter that knows YooKassa says
 * `waiting_for_capture`, and letting those strings reach the domain would make
 * every business rule depend on which provider happens to be configured.
 *
 * `ticketsAlreadyIssued` is how the second and third delivery of the same webhook
 * stay harmless. ТЗ §28 asks for "three webhooks → one paid order, one ticket
 * issuance", and that is only achievable if the evaluator knows whether the
 * side effect already happened — an idempotency key on the request is not enough,
 * because providers retry with different keys and sometimes none at all.
 */
final class SettlementRequest
{
    public function __construct(
        public readonly string $orderStatus,
        public readonly string $paymentStatus,
        public readonly Money $orderTotal,
        public readonly Money $reportedAmount,
        public readonly string $providerEvent,
        public readonly bool $ticketsAlreadyIssued = false,
    ) {
        if ($orderTotal->currency() !== $reportedAmount->currency()) {
            throw new DomainRuleViolation(
                sprintf(
                    'The order is in %s but the provider reported %s.',
                    $orderTotal->currency(),
                    $reportedAmount->currency()
                ),
                'CURRENCY_MISMATCH'
            );
        }

        if (! PaymentStateMachine::make()->isKnownState($providerEvent)) {
            throw new DomainRuleViolation(
                sprintf('"%s" is not a payment status this system understands.', $providerEvent),
                'UNKNOWN_PAYMENT_EVENT'
            );
        }
    }

    public function currency(): string
    {
        return $this->orderTotal->currency();
    }

    public function amountMatches(): bool
    {
        return $this->reportedAmount->minorUnits() === $this->orderTotal->minorUnits();
    }
}
