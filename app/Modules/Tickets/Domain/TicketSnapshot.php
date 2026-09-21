<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use App\Modules\Tickets\StateMachines\TicketStateMachine;

/**
 * The facts about a ticket that a check-in decision needs — and nothing else.
 *
 * DELIBERATELY EXCLUDES `holder_name`.
 *
 * The QR payload carries no personal data (ТЗ §43/§44) and neither does the
 * offline bundle: it holds ticket public ids, numbers and statuses only. A
 * scanner is an untrusted device held by a contractor, often offline, and
 * anything present in the object that travels to it is effectively public. If
 * this snapshot grew a holder name, the name would end up cached on a lost
 * phone — and under a data-protection regime that is a reportable leak, not a
 * design detail.
 *
 * Immutable by design: a decision must be made against the state that was read.
 * Mutating a snapshot mid-evaluation would make "why was this person refused?"
 * unanswerable later.
 */
final class TicketSnapshot
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $status,
        public readonly int $sessionId,
        public readonly int $eventId,
        public readonly string $ticketNumber = '',
        public readonly ?\DateTimeImmutable $usedAt = null,
        public readonly ?\DateTimeImmutable $revokedAt = null,
        public readonly ?\DateTimeImmutable $cancelledAt = null,
        public readonly ?\DateTimeImmutable $refundedAt = null,
        public readonly ?\DateTimeImmutable $expiredAt = null,
        public readonly ?string $revokedReason = null,
    ) {
        if ($publicId === '') {
            throw new DomainRuleViolation('A ticket snapshot needs the ticket public id.', 'INVALID_TICKET');
        }
    }

    public function isAdmissible(): bool
    {
        return $this->status === TicketStateMachine::ISSUED;
    }

    /** The moment the ticket stopped being valid, whichever way it happened. */
    public function invalidatedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt
            ?? $this->revokedAt
            ?? $this->refundedAt
            ?? $this->cancelledAt
            ?? $this->expiredAt;
    }
}
