<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use App\Modules\Tickets\StateMachines\TicketStateMachine;

/**
 * A ticket as refund sees it: just enough to decide whether it can be taken back.
 *
 * `status` is the only state there is. The timestamps (`used_at`, `refunded_at`,
 * `cancelled_at`) are written by the layer that applies this plan; the decision
 * only needs to know which of them are already set, and `status` implies that.
 *
 * No holder name, no QR payload, nothing personal: this object exists to decide
 * inventory and status, and ТЗ §43/§44 forbid personal data from travelling
 * anywhere near the offline bundle.
 */
final class RevocableTicket
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderItemId,
        public readonly int $ticketIndex,
        public readonly string $status,
    ) {
        if (! TicketStateMachine::make()->isKnownState($status)) {
            throw new DomainRuleViolation(
                sprintf('"%s" is not a ticket status.', $status),
                'INVALID_TICKET_STATUS'
            );
        }
    }

    /** The holder has already been through the door. */
    public function wasAdmitted(): bool
    {
        return $this->status === TicketStateMachine::USED;
    }

    /**
     * Can this ticket still be taken back?
     *
     * Only `issued` and `used` are non-terminal. Everything else
     * (`refunded`, `cancelled`, `revoked`, `expired`) is terminal, and re-revoking
     * it would either fail the state machine or, worse, rewrite a row that already
     * records an outcome.
     */
    public function isRevocable(): bool
    {
        return in_array(
            $this->status,
            [TicketStateMachine::ISSUED, TicketStateMachine::USED],
            true
        );
    }
}
