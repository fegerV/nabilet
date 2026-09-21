<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\StateMachines;

use Nabilet\Core\StateMachine\StateMachine;

/**
 * Inventory item (sellable seat / standing slot) lifecycle (ТЗ §23).
 *
 * `InventoryItem` is the unit that is actually sold (ТЗ §90). Its status is the
 * single source of truth for "can this be bought right now", which is why
 * availability must NEVER be cached (ТЗ §59) — every check reads this column
 * inside a transaction.
 *
 * Transition notes:
 *  - `held` → `available` happens on hold expiry or explicit release;
 *  - `held` → `sold` happens on payment confirmation;
 *  - `sold` → `available` is the refund path, and is only ever driven by the
 *    refund service — never by a direct status write;
 *  - `blocked` is a commercial decision (house seats, obstructed view) and is
 *    reversible; `disabled` means the seat does not exist for this session at all.
 */
final class InventoryItemStateMachine
{
    public const AVAILABLE = 'available';
    public const HELD = 'held';
    public const SOLD = 'sold';
    public const BLOCKED = 'blocked';
    public const DISABLED = 'disabled';

    public static function make(): StateMachine
    {
        return StateMachine::define(
            name: 'InventoryItem',
            initial: self::AVAILABLE,
            states: [
                self::AVAILABLE,
                self::HELD,
                self::SOLD,
                self::BLOCKED,
                self::DISABLED,
            ],
            transitions: [
                self::AVAILABLE => [self::HELD, self::SOLD, self::BLOCKED, self::DISABLED],
                self::HELD => [self::AVAILABLE, self::SOLD, self::DISABLED, self::BLOCKED],
                // sold -> available is the refund path only
                self::SOLD => [self::AVAILABLE, self::DISABLED],
                self::BLOCKED => [self::AVAILABLE, self::DISABLED, self::HELD],
                self::DISABLED => [self::AVAILABLE, self::BLOCKED],
            ],
            terminal: [],
        );
    }

    /** @return list<string> statuses in which the item can be added to a cart */
    public static function purchasable(): array
    {
        return [self::AVAILABLE];
    }
}
