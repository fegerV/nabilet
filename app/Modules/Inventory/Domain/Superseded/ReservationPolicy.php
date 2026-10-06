<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Domain\Superseded;

/**
 * Whether a cart may take N units of an item, and why not (ТЗ §24).
 *
 * Returns a reason string rather than throwing, because "this seat is taken" is a
 * normal answer at a box office, not an exception — the caller turns it into a
 * 409 with a message the customer can act on.
 *
 * The two rules that are easy to miss:
 *
 *   A SEAT IS ALWAYS QUANTITY 1. `capacity` is pinned to 1 by
 *   ck_inventory_seat_capacity, so asking for two of a seat is not "two units",
 *   it is a malformed request — and if it were silently clamped to 1 the customer
 *   would be charged for one ticket while believing they bought two.
 *
 *   THE PER-ORDER CAP IS AN ANTI-SCALPING GUARD, not a technical limit. It
 *   belongs in the domain rather than in a controller because it has to apply to
 *   carts, holds and orders identically — enforced in one place it is a rule,
 *   enforced in three it is a suggestion.
 *
 * ВНИМАНИЕ: В БОЕВОМ ПУТИ ЭТОТ КЛАСС НЕ УЧАСТВУЕТ.
 * -----------------------------------------------------------------------
 * Здесь стояло «Eight is configured (NABILET_MAX_SEATS_PER_ORDER)» — это было
 * неправдой сразу в двух смыслах, и оба вскрылись на живом стенде:
 *
 *   1. `NABILET_MAX_SEATS_PER_ORDER` не читает НИ ОДИН env() — переменная
 *      мёртвая (как `NABILET_HOLD_TTL` и `NABILET_HOLD_GRACE` из той же серии).
 *   2. Конструктор по умолчанию берёт `$maxUnitsPerOrder = 8`, но
 *      `OrderPlacement` — единственный потребитель — нигде в приложении не
 *      создаётся (`grep -rn "new OrderPlacement" app/` пуст), поэтому и это
 *      число до продакшена не доходит.
 *
 * Фактический лимит живёт в `CartController::addItem()` и берётся из
 * `config('nabilet.checkout.max_items_per_order')` (CHECKOUT_MAX_ITEMS,
 * по умолчанию 10). Проверено запросом: `quantity=10` → 201, затем
 * `POST /cart/checkout` → 200, то есть оформление заказа прошло и политика
 * не вмешалась.
 *
 * Прежде чем «починить» лимит здесь — включите этот класс в боевой путь
 * (создавайте `OrderPlacement` в сервисе оформления и передавайте число из
 * конфига). Иначе получится ровно то, что было: правило, которое выглядит
 * рабочим, но ни на что не влияет.
 */
final class ReservationPolicy
{
    public const REASON_QUANTITY_NOT_POSITIVE = 'quantity_not_positive';
    public const REASON_SEAT_QUANTITY_MUST_BE_ONE = 'seat_quantity_must_be_one';
    public const REASON_EXCEEDS_ORDER_LIMIT = 'exceeds_order_limit';
    public const REASON_INSUFFICIENT_AVAILABILITY = 'insufficient_availability';

    public function __construct(private readonly int $maxUnitsPerOrder = 8)
    {
        if ($maxUnitsPerOrder < 1) {
            throw new \Nabilet\Core\Errors\DomainRuleViolation(
                'The per-order unit limit must be at least 1.',
                'INVALID_ORDER_LIMIT'
            );
        }
    }

    /** null means "allowed"; otherwise a machine-readable reason. */
    public function refusalFor(InventoryStock $stock, int $quantity): ?string
    {
        // ck_holds_quantity: quantity > 0
        if ($quantity < 1) {
            return self::REASON_QUANTITY_NOT_POSITIVE;
        }

        if ($stock->isSeat() && $quantity !== 1) {
            return self::REASON_SEAT_QUANTITY_MUST_BE_ONE;
        }

        if ($quantity > $this->maxUnitsPerOrder) {
            return self::REASON_EXCEEDS_ORDER_LIMIT;
        }

        if (! $stock->canReserve($quantity)) {
            return self::REASON_INSUFFICIENT_AVAILABILITY;
        }

        return null;
    }

    public function allows(InventoryStock $stock, int $quantity): bool
    {
        return $this->refusalFor($stock, $quantity) === null;
    }

    /**
     * How many more units this cart may still take, given what it already holds.
     * A cart that already holds 6 of an 8-seat limit may add 2 — not 8.
     */
    public function remainingAllowance(int $alreadyTaken): int
    {
        return max(0, $this->maxUnitsPerOrder - $alreadyTaken);
    }
}
