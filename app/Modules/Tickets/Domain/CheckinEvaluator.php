<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Domain;

use App\Modules\Tickets\StateMachines\TicketStateMachine;

/**
 * Decides what happens at the door.
 *
 * PURE: given a scan and a ticket, it returns the outcome. It does not write
 * anything. Persisting the scan row and applying `resultingStatus` is the
 * caller's job — which is what makes the rules testable without a database, and
 * what keeps the decision auditable: the outcome can be logged before it is
 * applied.
 *
 * THE PART THAT IS EASY TO GET WRONG IS OFFLINE SYNC.
 *   An online scan asks "may this person in?". An offline sync reports "I let
 *   this person in at 19:02" — possibly hours after the fact, against a ticket
 *   whose status has since changed. The server cannot un-admit anyone. So:
 *
 *     - device admitted, ticket still issued      -> record as admitted, mark used
 *     - device admitted, ticket no longer valid   -> conflict: revoke (§44)
 *     - device refused                            -> record the refusal
 *
 *   Marking a conflicted ticket `used` would be the comfortable answer and the
 *   wrong one: it reports a clean entry for a ticket that was refunded or
 *   chargebacked, and the discrepancy surfaces weeks later in reconciliation.
 *
 * §32 also drives one deliberate non-error: re-scanning a used ticket returns
 * `already_used` with the original timestamp, not a rejection and not a 500. The
 * operator needs the history to resolve the argument at the door.
 */
final class CheckinEvaluator
{
    /**
     * @param TicketSnapshot|null $ticket null when the QR does not resolve to a
     *                                    ticket at all (unknown or garbage code)
     */
    public function evaluate(ScanRequest $scan, ?TicketSnapshot $ticket): ScanOutcome
    {
        if ($ticket === null) {
            return ScanOutcome::refused(ScanOutcome::NOT_FOUND, 'the QR does not resolve to a ticket');
        }

        // A ticket for a different session is not "invalid" — it is a different
        // event. Reporting it as revoked would send staff looking for fraud.
        if ($ticket->sessionId !== $scan->sessionId) {
            return ScanOutcome::refused(
                ScanOutcome::WRONG_SESSION,
                'this ticket is for another session'
            );
        }

        if ($scan->isOfflineSync()) {
            return $this->evaluateOfflineSync($scan, $ticket);
        }

        return $this->evaluateOnline($ticket);
    }

    private function evaluateOnline(TicketSnapshot $ticket): ScanOutcome
    {
        return match ($ticket->status) {
            TicketStateMachine::ISSUED => ScanOutcome::admitted(),

            // §32: not an error. The operator is shown when it was first scanned.
            TicketStateMachine::USED => ScanOutcome::alreadyUsed($ticket->usedAt),

            TicketStateMachine::REVOKED => ScanOutcome::refused(
                ScanOutcome::REVOKED,
                $ticket->revokedReason ?? 'this ticket was revoked'
            ),
            TicketStateMachine::CANCELLED => ScanOutcome::refused(
                ScanOutcome::CANCELLED,
                'this ticket was cancelled'
            ),
            TicketStateMachine::REFUNDED => ScanOutcome::refused(
                ScanOutcome::REFUNDED,
                'this ticket was refunded'
            ),
            TicketStateMachine::EXPIRED => ScanOutcome::refused(
                ScanOutcome::EXPIRED,
                'this ticket has expired'
            ),

            default => ScanOutcome::refused(ScanOutcome::NOT_FOUND, 'unknown ticket status'),
        };
    }

    private function evaluateOfflineSync(ScanRequest $scan, TicketSnapshot $ticket): ScanOutcome
    {
        // The device did not let anyone in — nothing to reconcile, just record.
        // Notably this covers a VALID ticket that the device refused because its
        // bundle predates the sale, which is worth reporting on its own.
        if (! $scan->deviceAdmitted) {
            return ScanOutcome::refusedByDevice($ticket->status);
        }

        if ($ticket->status === TicketStateMachine::ISSUED) {
            return ScanOutcome::admitted();
        }

        if ($ticket->status === TicketStateMachine::USED) {
            // Already used before the device scanned it: someone was waved through
            // on a ticket that had already entered. Revoke rather than ignore.
            return ScanOutcome::conflictRevoked(
                'admitted offline but the ticket was already used'
            );
        }

        // §44: the conflict is resolved by revocation. The person is inside and
        // cannot be ejected, so the record must say what actually happened.
        return match ($ticket->status) {
            TicketStateMachine::REVOKED => ScanOutcome::conflictRevoked(
                $ticket->revokedReason ?? 'admitted offline but the ticket was revoked'
            ),
            TicketStateMachine::REFUNDED => ScanOutcome::conflictRevoked(
                'admitted offline but the ticket was refunded'
            ),
            TicketStateMachine::CANCELLED => ScanOutcome::conflictRevoked(
                'admitted offline but the ticket was cancelled'
            ),
            TicketStateMachine::EXPIRED => ScanOutcome::conflictRevoked(
                'admitted offline but the ticket has expired'
            ),
            default => ScanOutcome::refused(ScanOutcome::NOT_FOUND, 'unknown ticket status'),
        };
    }
}
