<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\StateMachines;

use Nabilet\Core\StateMachine\StateMachine;

/**
 * Ticket lifecycle (ТЗ §29, §30, §32).
 *
 * This machine encodes the check-in rules that the Android Checker depends on:
 *
 *   - `issued → used` is the single valid entry transition. `used_at` is the only
 *     thing that makes a ticket non-reusable, and it is set exactly once.
 *   - There is deliberately NO `used → issued` transition. "Undo check-in" would
 *     make double entry possible through a support mistake; a mistake at the door
 *     is resolved by `revoked` plus issuing a replacement, which leaves an audit
 *     trail.
 *   - `expired` is set by a scheduled job after the session ends, so old tickets
 *     cannot be replayed at a later event.
 *   - `revoked` is for fraud, chargebacks and duplicate-scan investigations.
 *
 * Consequence for the check-in API: a second scan of a `used` ticket must return
 * "already used" with the original timestamp and device (ТЗ §32), not an error —
 * the scanner needs to show the operator what happened.
 */
final class TicketStateMachine
{
    public const ISSUED = 'issued';
    public const USED = 'used';
    public const REFUNDED = 'refunded';
    /**
     * `cancelled`, two L — ck_tickets_status. Only PAYMENTS use the one-L
     * `canceled`, because that spelling comes from the payment provider's
     * vocabulary rather than from us.
     */
    public const CANCELLED = 'cancelled';
    public const REVOKED = 'revoked';
    public const EXPIRED = 'expired';

    public static function make(): StateMachine
    {
        return StateMachine::define(
            name: 'Ticket',
            initial: self::ISSUED,
            states: [
                self::ISSUED,
                self::USED,
                self::REFUNDED,
                self::CANCELLED,
                self::REVOKED,
                self::EXPIRED,
            ],
            transitions: [
                self::ISSUED => [
                    self::USED,
                    self::REFUNDED,
                    self::CANCELLED,
                    self::REVOKED,
                    self::EXPIRED,
                ],
                // a used ticket can only be revoked — never returned to `issued`
                self::USED => [self::REVOKED],
                self::REFUNDED => [],
                self::CANCELLED => [],
                self::REVOKED => [],
                self::EXPIRED => [],
            ],
            terminal: [self::REFUNDED, self::CANCELLED, self::REVOKED, self::EXPIRED],
        );
    }

    /** @return list<string> statuses the check-in API accepts at the door */
    public static function admissible(): array
    {
        return [self::ISSUED];
    }
}
