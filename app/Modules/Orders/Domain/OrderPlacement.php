<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Domain;

use Nabilet\Core\Support\Money;
use Nabilet\Modules\Inventory\Domain\ReservationPolicy;
use Nabilet\Modules\Inventory\Domain\SeatHold;

/**
 * Decides whether a cart may become an order, and at what price (ТЗ §23, §24, §84).
 *
 * This is the join point of three things that each look fine alone and break
 * together: the cart's price snapshot, the live inventory, and the hold that is
 * supposed to be protecting the seats. Every bug worth having in a ticketing
 * checkout lives at this join:
 *
 *   - the hold expired, the seats went back into the pool, and possibly into
 *     somebody else's cart — but the cart still lists them;
 *   - the organizer changed the price while the cart sat open;
 *   - the customer double-submitted and the same cart is being converted twice.
 *
 * So the evaluator checks, in this order:
 *
 *   1. CART STILL ACTIVE          kills the double-submit outright
 *   2. CART NOT EMPTY             ck_cart_items_quantity implies at least one line
 *   3. PER-LINE QUANTITY RULES    seat = exactly 1; per-order cap (anti-scalping)
 *   4. PER-LINE AVAILABILITY      the units are still there
 *   5. PER-LINE HOLD COVERAGE     they are held for THIS cart and still convertible
 *   6. PER-LINE PRICE DRIFT       never charge more than the customer was shown
 *
 * The order matters: a cart that fails an earlier gate is reported with that
 * reason rather than a later one, because the earlier reason is the one the
 * customer can act on first. There is no point telling someone the price moved if
 * their seats are already gone.
 *
 * Precedence is explicit in REASON_PRECEDENCE rather than an incidental property
 * of the order of the loops — the loop order is a performance detail, the reported
 * reason is an API contract.
 */
final class OrderPlacement
{
    public const REASON_CART_NOT_ACTIVE = 'cart_not_active';
    public const REASON_EMPTY_CART = 'empty_cart';
    public const REASON_QUANTITY_EXCEEDED = 'quantity_exceeded';
    public const REASON_INSUFFICIENT_AVAILABILITY = 'insufficient_availability';
    public const REASON_HOLD_MISSING = 'hold_missing';
    public const REASON_HOLD_EXPIRED = 'hold_expired';
    public const REASON_PRICE_INCREASED = 'price_increased';

    public const WARNING_HOLD_OVERRUN = 'hold_overrun';
    public const WARNING_PRICE_REDUCED = 'price_reduced';

    /** Lower number = reported first. */
    private const REASON_PRECEDENCE = [
        self::REASON_CART_NOT_ACTIVE => 10,
        self::REASON_EMPTY_CART => 20,
        self::REASON_QUANTITY_EXCEEDED => 30,
        self::REASON_INSUFFICIENT_AVAILABILITY => 40,
        self::REASON_HOLD_MISSING => 50,
        self::REASON_HOLD_EXPIRED => 60,
        self::REASON_PRICE_INCREASED => 70,
    ];

    public function __construct(private readonly ReservationPolicy $policy = new ReservationPolicy())
    {
    }

    public function assess(CartCheckout $checkout): CheckoutVerdict
    {
        if (! CartState::canCheckOut($checkout->cartStatus)) {
            return CheckoutVerdict::refused(self::REASON_CART_NOT_ACTIVE);
        }

        if ($checkout->isEmpty()) {
            return CheckoutVerdict::refused(self::REASON_EMPTY_CART);
        }

        /** @var list<array{inventory_item_id: int, reason: string}> $problems */
        $problems = [];
        /** @var list<string> $warnings */
        $warnings = [];
        /** @var list<SeatHold> $held */
        $held = [];

        foreach ($checkout->lines as $line) {
            $lineProblems = $this->assessLine($checkout, $line, $warnings, $held);
            $problems = array_merge($problems, $lineProblems);
        }

        if ($problems !== []) {
            return CheckoutVerdict::refused($this->leadingReason($problems), $problems, $warnings);
        }

        $subtotal = Money::zero($checkout->currency);
        foreach ($checkout->lines as $line) {
            $subtotal = $subtotal->plus($line->effectiveSubtotal());
        }

        return CheckoutVerdict::ready($checkout->lines, $held, $subtotal, $warnings);
    }

    /**
     * @param list<string>    $warnings appended to
     * @param list<SeatHold>  $held     appended to
     * @return list<array{inventory_item_id: int, reason: string}>
     */
    private function assessLine(
        CartCheckout $checkout,
        CheckoutLine $line,
        array &$warnings,
        array &$held,
    ): array {
        $problems = [];

        $add = static function (string $reason) use (&$problems, $line): void {
            $entry = ['inventory_item_id' => $line->inventoryItemId, 'reason' => $reason];

            // Idempotent: several checks below can reach the same conclusion
            // (e.g. "not enough left" is true of both the policy and the live
            // stock) and duplicating it would double the entries on the wire.
            if (! in_array($entry, $problems, true)) {
                $problems[] = $entry;
            }
        };

        // 3. quantity rules: seat = 1, per-order cap, positive.
        //    Availability is NOT reported here — step 4 owns that reason so it
        //    carries its own error code instead of the generic quantity one.
        $refusal = $this->policy->refusalFor($line->stock, $line->quantity);
        if ($refusal !== null && $refusal !== ReservationPolicy::REASON_INSUFFICIENT_AVAILABILITY) {
            $add(self::REASON_QUANTITY_EXCEEDED);
        }

        // 4. availability
        if (! $line->canStillBeSold()) {
            $add(self::REASON_INSUFFICIENT_AVAILABILITY);
        }

        // 5. hold coverage
        $covering = $checkout->holdsFor($line->inventoryItemId);
        $liveQuantity = 0;
        foreach ($covering as $hold) {
            if (! $hold->isConvertibleAt($checkout->now)) {
                continue;
            }

            $liveQuantity += $hold->quantity;
            $held[] = $hold;

            if ($hold->isOverrunAt($checkout->now)) {
                $warnings[] = self::WARNING_HOLD_OVERRUN;
            }
        }

        // A hold that existed for this line but can no longer be converted —
        // past its grace window, so the units may have gone back into the pool.
        // Released and converted holds are excluded: they are not "expired", they
        // are finished, and "expired" is the one reason a customer can act on
        // ("pick your seats again"), so it must mean something specific.
        $lapsed = array_filter(
            $covering,
            fn (SeatHold $h): bool => ! $h->isReleased()
                && ! $h->isConverted()
                && ! $h->isConvertibleAt($checkout->now)
        );

        if ($liveQuantity < $line->quantity) {
            // Two ways to be short, and they are different conversations:
            // a hold lapsed (re-select) versus units that were never reserved at
            // all (partial coverage — the cart asked for more than it holds).
            $add($lapsed === [] ? self::REASON_HOLD_MISSING : self::REASON_HOLD_EXPIRED);
        }

        // 6. price drift — only an increase blocks
        if ($line->priceRose()) {
            $add(self::REASON_PRICE_INCREASED);
        } elseif ($line->priceFell()) {
            $warnings[] = self::WARNING_PRICE_REDUCED;
        }

        return $problems;
    }

    /**
     * @param list<array{inventory_item_id: int, reason: string}> $problems
     */
    private function leadingReason(array $problems): string
    {
        $best = null;
        $bestWeight = PHP_INT_MAX;

        foreach ($problems as $problem) {
            $weight = self::REASON_PRECEDENCE[$problem['reason']] ?? PHP_INT_MAX - 1;
            if ($weight < $bestWeight) {
                $best = $problem['reason'];
                $bestWeight = $weight;
            }
        }

        return $best ?? self::REASON_CART_NOT_ACTIVE;
    }
}
