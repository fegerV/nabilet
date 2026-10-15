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
 *
 * `sold_out` — capacity exhausted, and it is NOT the same thing as `sold`.
 *   A position whose `available_quantity` reached zero is unavailable for sale,
 *   but nothing has been paid for it: the units are sitting in carts. For a seat
 *   (`capacity = 1`) that is the normal state from the moment it is added to a
 *   cart, so `held` is the exception there, not the rule — `CartItemService`
 *   picks `held` only while free capacity remains (standing zones).
 *
 *   This state was missing from the machine while three production sites already
 *   wrote it (`CartItemService::addItem`, `removeItem`, `releaseHolds`). The
 *   consequence was not cosmetic: `PaymentService::markInventorySoldForOrder()`
 *   guards the `→ sold` write with `can()`, so `can('sold_out', 'sold')` returned
 *   false and a PAID position stayed `sold_out` forever — it appeared in no `sold`
 *   report and `OrderService` reset it to `available` on cancellation as readily
 *   as a hold. Pinned by `tests/Feature/Inventory/InventorySoldOnPaymentTest.php`.
 *
 *   Transitions below are the ones the code performs. `blocked`/`disabled` have
 *   no writer anywhere in the application yet (they exist for the admin surface);
 *   they are listed for `sold_out` by symmetry with `held`, because "capacity is
 *   gone" is no more of a commercial commitment than "capacity is reserved".
 */
final class InventoryItemStateMachine
{
    public const AVAILABLE = 'available';
    public const HELD = 'held';
    public const SOLD_OUT = 'sold_out';
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
                self::SOLD_OUT,
                self::SOLD,
                self::BLOCKED,
                self::DISABLED,
            ],
            transitions: [
                self::AVAILABLE => [self::HELD, self::SOLD_OUT, self::SOLD, self::BLOCKED, self::DISABLED],
                self::HELD => [self::AVAILABLE, self::SOLD_OUT, self::SOLD, self::DISABLED, self::BLOCKED],
                // capacity came back (hold released, cart emptied, order cancelled),
                // or the position was paid for
                self::SOLD_OUT => [self::AVAILABLE, self::HELD, self::SOLD, self::DISABLED, self::BLOCKED],
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
