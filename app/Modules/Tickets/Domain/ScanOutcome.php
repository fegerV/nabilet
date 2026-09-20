<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Domain;

/**
 * What the door should do, and what should be recorded.
 *
 * `result` is stored verbatim in `ticket_scans.result`, so the constants here ARE
 * the reporting vocabulary — analytics and support read them back out of the
 * database. Renaming one is a data migration, not a refactor.
 *
 * Two fields drive behaviour:
 *
 *   admits          — open the barrier.
 *   resultingStatus — what the ticket becomes, or null when it does not change.
 *
 * `already_used` admits nobody AND carries `usedAt`: ТЗ §32 requires the operator
 * to be shown when and where the ticket was first scanned, because "this ticket
 * was used at 19:04 at Gate B" is the answer that resolves the argument at the
 * door. A bare "denied" would start one.
 */
final class ScanOutcome implements \JsonSerializable
{
    public const ADMITTED = 'admitted';
    public const ALREADY_USED = 'already_used';
    public const REVOKED = 'revoked';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';
    public const EXPIRED = 'expired';
    public const WRONG_SESSION = 'wrong_session';
    public const NOT_FOUND = 'not_found';

    /**
     * The device let someone in offline, and the ticket has since stopped being
     * valid. §44: the conflict is resolved by revocation — the person is inside,
     * the ticket is marked revoked, and the incident is recorded rather than
     * silently dropped.
     */
    public const CONFLICT_REVOKED = 'conflict_revoked';

    /**
     * The device said no. Nobody entered and the ticket is unchanged — but the
     * scan is still recorded, because "the scanner refused a valid ticket" is a
     * real and actionable event: it means the device's bundle was stale and the
     * ticket was issued after it was generated.
     */
    public const REFUSED_BY_DEVICE = 'refused_by_device';

    private function __construct(
        public readonly string $result,
        public readonly bool $admits,
        public readonly ?string $resultingStatus,
        public readonly ?\DateTimeImmutable $usedAt = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function admitted(): self
    {
        return new self(
            result: self::ADMITTED,
            admits: true,
            resultingStatus: \Nabilet\Modules\Tickets\StateMachines\TicketStateMachine::USED,
        );
    }

    public static function alreadyUsed(?\DateTimeImmutable $usedAt): self
    {
        return new self(
            result: self::ALREADY_USED,
            admits: false,
            resultingStatus: null,
            usedAt: $usedAt,
        );
    }

    public static function refused(string $result, ?string $reason = null): self
    {
        return new self(
            result: $result,
            admits: false,
            resultingStatus: null,
            reason: $reason,
        );
    }

    /** The device refused; nobody entered and the ticket is left alone. */
    public static function refusedByDevice(string $ticketStatus): self
    {
        return new self(
            result: self::REFUSED_BY_DEVICE,
            admits: false,
            resultingStatus: null,
            reason: 'the device refused this scan; the ticket is ' . $ticketStatus,
        );
    }

    /**
     * Admitted offline, invalid now. The ticket ends up `revoked` — not `used` —
     * so that the report shows a chargeback/duplicate rather than a clean entry.
     */
    public static function conflictRevoked(string $reason): self
    {
        return new self(
            result: self::CONFLICT_REVOKED,
            admits: false,
            resultingStatus: \Nabilet\Modules\Tickets\StateMachines\TicketStateMachine::REVOKED,
            reason: $reason,
        );
    }

    /** @return array{result: string, admits: bool, resulting_status: string|null, used_at: string|null, reason: string|null} */
    public function jsonSerialize(): array
    {
        return [
            'result' => $this->result,
            'admits' => $this->admits,
            'resulting_status' => $this->resultingStatus,
            'used_at' => $this->usedAt?->format(\DATE_ATOM),
            'reason' => $this->reason,
        ];
    }
}
