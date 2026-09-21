<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Support\Money;
use App\Modules\Inventory\Domain\SeatHold;

/**
 * The answer to "may this cart become an order right now?".
 *
 * It is a result object rather than an exception because most refusals are
 * ordinary: someone else bought the seat, the hold timed out, the price moved.
 * The caller turns `reason()` into a 409 with a message the customer can act on,
 * and `throwIfRefused()` is there for the one path where the refusal genuinely is
 * exceptional.
 *
 * `warnings` carries things that did NOT block: a hold that was converted inside
 * its grace window, a price that came down in the customer's favour. Both are
 * worth logging and worth telling the customer, but neither justifies failing a
 * sale that the inventory can still honour.
 */
final class CheckoutVerdict
{
    /**
     * @param list<array{inventory_item_id: int, reason: string}> $problems
     * @param list<string>                                        $warnings
     * @param list<CheckoutLine>                                  $lines
     * @param list<SeatHold>                                      $holdsToConvert
     */
    private function __construct(
        private readonly bool $ok,
        private readonly ?string $reason,
        private readonly array $problems,
        private readonly array $warnings,
        private readonly array $lines,
        private readonly array $holdsToConvert,
        private readonly Money $subtotal,
    ) {
    }

    /** @param list<array{inventory_item_id: int, reason: string}> $problems */
    public static function refused(string $reason, array $problems = [], array $warnings = []): self
    {
        return new self(false, $reason, $problems, $warnings, [], [], Money::zero('RUB'));
    }

    /**
     * @param list<CheckoutLine> $lines
     * @param list<SeatHold>     $holdsToConvert
     * @param list<string>       $warnings
     */
    public static function ready(array $lines, array $holdsToConvert, Money $subtotal, array $warnings = []): self
    {
        return new self(true, null, [], $warnings, $lines, $holdsToConvert, $subtotal);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /** @return list<array{inventory_item_id: int, reason: string}> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<CheckoutLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<SeatHold> */
    public function holdsToConvert(): array
    {
        return $this->holdsToConvert;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    /**
     * @throws ConflictError mapped to the §66 envelope by the HTTP layer
     */
    public function throwIfRefused(): void
    {
        if ($this->ok) {
            return;
        }

        throw new ConflictError(
            self::messageFor((string) $this->reason),
            self::errorCodeFor((string) $this->reason),
            ['problems' => $this->problems]
        );
    }

    private static function messageFor(string $reason): string
    {
        return match ($reason) {
            OrderPlacement::REASON_CART_NOT_ACTIVE =>
                'This cart can no longer be checked out. Please start a new one.',
            OrderPlacement::REASON_EMPTY_CART => 'The cart is empty.',
            OrderPlacement::REASON_PRICE_INCREASED =>
                'The price changed while your seats were held. Please confirm the new price.',
            OrderPlacement::REASON_HOLD_EXPIRED =>
                'Your seat reservation has expired. Please select your seats again.',
            OrderPlacement::REASON_HOLD_MISSING =>
                'These seats are not reserved for this cart. Please select your seats again.',
            OrderPlacement::REASON_INSUFFICIENT_AVAILABILITY =>
                'Some of the selected seats are no longer available.',
            OrderPlacement::REASON_QUANTITY_EXCEEDED =>
                'This order exceeds the maximum number of seats per order.',
            default => 'The cart cannot be checked out.',
        };
    }

    private static function errorCodeFor(string $reason): string
    {
        return match ($reason) {
            OrderPlacement::REASON_CART_NOT_ACTIVE => 'CART_NOT_ACTIVE',
            OrderPlacement::REASON_EMPTY_CART => 'CART_EMPTY',
            OrderPlacement::REASON_PRICE_INCREASED => 'PRICE_CHANGED',
            OrderPlacement::REASON_HOLD_EXPIRED => 'HOLD_EXPIRED',
            OrderPlacement::REASON_HOLD_MISSING => 'HOLD_MISSING',
            OrderPlacement::REASON_INSUFFICIENT_AVAILABILITY => 'SEAT_UNAVAILABLE',
            OrderPlacement::REASON_QUANTITY_EXCEEDED => 'ORDER_LIMIT_EXCEEDED',
            default => 'CHECKOUT_REFUSED',
        };
    }
}
