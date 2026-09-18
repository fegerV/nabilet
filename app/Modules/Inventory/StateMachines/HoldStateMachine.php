<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\StateMachines;

use Nabilet\Core\StateMachine\StateMachine;

/**
 * Temporary seat hold lifecycle (ТЗ §24, §84).
 *
 * The hold is the mechanism that stops two buyers from paying for the same seat.
 * Its lifetime is bounded by `expires_at` (10 minutes by default), and expiry is
 * driven by a scheduled job — never by a lazy check on read, because a seat that
 * only *looks* available is worse than one that is obviously held.
 *
 * `converted` is the happy path (hold → order → payment → sold). The three
 * terminal states are kept distinct rather than collapsed into "closed" because
 * they have different analytics and different customer messaging:
 *   - expired   → "your reservation timed out"
 *   - released  → "you removed this seat"
 *   - converted → normal completion
 */
final class HoldStateMachine
{
    public const ACTIVE = 'active';
    public const CONVERTED = 'converted';
    public const EXPIRED = 'expired';
    public const RELEASED = 'released';

    public static function make(): StateMachine
    {
        return StateMachine::define(
            name: 'SeatHold',
            initial: self::ACTIVE,
            states: [
                self::ACTIVE,
                self::CONVERTED,
                self::EXPIRED,
                self::RELEASED,
            ],
            transitions: [
                self::ACTIVE => [self::CONVERTED, self::EXPIRED, self::RELEASED],
                self::CONVERTED => [],
                self::EXPIRED => [],
                self::RELEASED => [],
            ],
            terminal: [self::CONVERTED, self::EXPIRED, self::RELEASED],
        );
    }
}
