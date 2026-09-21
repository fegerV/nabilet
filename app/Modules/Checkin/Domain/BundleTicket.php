<?php

declare(strict_types=1);

namespace App\Modules\Checkin\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use App\Modules\Tickets\Domain\TicketSnapshot;

/**
 * One ticket entry as it appears in an offline bundle.
 *
 * THE ABSENCE OF A NAME IS STRUCTURAL, NOT DISCIPLINARY.
 * This class has no field in which a holder name could be carried. That is
 * deliberate: a scanner is an untrusted device held by a contractor, often
 * offline for hours, and anything present in an object that travels to it is
 * effectively published. ТЗ §43/§44 forbid personal data in the QR payload for
 * exactly this reason, and the bundle is the same object at larger scale — a
 * list of every ticket for a session, sitting in a file on a phone.
 *
 * `fromSnapshot()` is the intended constructor. It takes a TicketSnapshot, which
 * is already defined as "the facts a check-in decision needs and nothing else",
 * so a bundle entry cannot be built from a row that still has the customer
 * attached. Adding a holder name to the bundle becomes a type error rather than
 * a code-review miss.
 *
 * `sessionId` is carried even though the bundle is already scoped to a session,
 * because it makes "this ticket belongs to another session" detectable at build
 * time. Without it the builder would happily emit a bundle for session 5 that
 * admits someone into session 6.
 */
final class BundleTicket implements \JsonSerializable
{
    public function __construct(
        public readonly string $publicId,
        public readonly string $number,
        public readonly string $status,
        public readonly int $sessionId,
    ) {
        if ($publicId === '') {
            throw new DomainRuleViolation(
                'A bundle ticket needs its public id; it is what the QR resolves to.',
                'INVALID_BUNDLE_TICKET'
            );
        }

        if ($status === '') {
            throw new DomainRuleViolation(
                'A bundle ticket needs a status; the device decides offline from it.',
                'INVALID_BUNDLE_TICKET'
            );
        }

        if ($sessionId <= 0) {
            throw new DomainRuleViolation(
                sprintf('A bundle ticket must name its session, got %d.', $sessionId),
                'INVALID_BUNDLE_TICKET'
            );
        }
    }

    public static function fromSnapshot(TicketSnapshot $snapshot): self
    {
        return new self(
            publicId: $snapshot->publicId,
            number: $snapshot->ticketNumber,
            status: $snapshot->status,
            sessionId: $snapshot->sessionId,
        );
    }

    /** @return array{id: string, n: string, s: string} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->publicId,
            'n' => $this->number,
            's' => $this->status,
        ];
    }
}
