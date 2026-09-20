<?php

declare(strict_types=1);

namespace Nabilet\Modules\Cart\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Inventory\Domain\SeatHold;

/**
 * What may go into a cart, and how long a cart may live (ТЗ §23, §24, §84).
 *
 * Every rule here closes a gap that was reproduced against MySQL 8.4 before it was
 * written. They are collected in docs/REVIEW-spec-bundle.md §3.16.
 *
 * ORDER OF THE CHECKS IS PART OF THE CONTRACT.
 *   The state of the CART outranks the state of the LINE: a dead cart cannot
 *   receive anything, so there is no point reporting that the line is also
 *   malformed. Among line-level faults, belonging outranks arithmetic — a seat at
 *   the wrong performance cannot be honoured at any price, whereas a wrong total
 *   is at least a fixable number. Reordering these changes which message the
 *   customer sees, so the order is asserted by a test.
 *
 * WHAT IS DELIBERATELY NOT HERE:
 *   Availability (`available_quantity`) and the per-order cap belong to
 *   ReservationPolicy; repricing at checkout belongs to Orders\Domain\CheckoutLine.
 *   Re-implementing either would give one business rule two homes that can drift.
 */
final class CartPolicy
{
    /** @param list<SeatHold> $holds */
    public function __construct(private readonly array $holds = [])
    {
        foreach ($holds as $hold) {
            if (! $hold instanceof SeatHold) {
                throw new DomainRuleViolation('CartPolicy accepts only SeatHold instances.', 'INVALID_HOLD');
            }
        }
    }

    /**
     * May this line be written into this cart?
     */
    public function addDecision(Cart $cart, CartItem $item, \DateTimeImmutable $now): CartDecision
    {
        if (! $cart->acceptsChanges()) {
            return CartDecision::denied(
                CartDecision::CART_NOT_ACTIVE,
                sprintf(
                    'Cart %s is "%s"; only an active cart accepts items.',
                    (string) $cart->id,
                    $cart->status
                )
            );
        }

        if ($cart->isExpiredAt($now)) {
            return CartDecision::denied(
                CartDecision::CART_EXPIRED,
                sprintf('Cart %s expired at %s.', (string) $cart->id, $cart->expiresAt?->format('c') ?? '—')
            );
        }

        // The gap the schema cannot close: cart_items.inventory_item_id checks the
        // item exists, not that it belongs to the session the cart is for.
        if (! $item->belongsToSession($cart->sessionId)) {
            return CartDecision::denied(
                CartDecision::WRONG_SESSION,
                sprintf(
                    'Cart %s is for session %d but item %d belongs to session %d. '
                    . 'A cart may only hold inventory of the session it sells.',
                    (string) $cart->id,
                    $cart->sessionId,
                    $item->inventoryItemId,
                    $item->sessionId
                )
            );
        }

        if (! $item->hasCoherentMoney()) {
            return CartDecision::denied(
                CartDecision::INCOHERENT_MONEY,
                sprintf(
                    'Cart line %d stores %d but %d × %d is %d.',
                    $item->inventoryItemId,
                    $item->totalPrice->minorUnits(),
                    $item->quantity,
                    $item->unitPrice->minorUnits(),
                    $item->expectedTotal()->minorUnits()
                )
            );
        }

        // uq_cart_inventory: one row per item per cart. "Add it again" is an
        // UPDATE of quantity, and saying so here is what keeps the caller from
        // issuing an INSERT that dies on a duplicate key.
        if ($cart->unitsOf($item->inventoryItemId) > 0) {
            return CartDecision::merge(sprintf(
                'Item %d is already in cart %s with %d unit(s); update the quantity instead of inserting a second row.',
                $item->inventoryItemId,
                (string) $cart->id,
                $cart->unitsOf($item->inventoryItemId)
            ));
        }

        return CartDecision::allowed();
    }

    /**
     * Is this cart's lifetime sane, given what it holds and the performance it sells?
     *
     * `carts.expires_at` is NULLable, and nothing in the schema relates it to the
     * holds or to the session — verified on MySQL 8.4, where `expires_at` was set
     * past `sessions.starts_at` with no complaint.
     *
     * A cart with NO holds needs no expiry: it is holding nothing, so an endless
     * one is merely untidy. A cart WITH holds and no expiry is the defect — it
     * stays open forever while the seats it offers go back to the pool.
     */
    public function expiryDecision(Cart $cart, \DateTimeImmutable $sessionStartsAt): CartDecision
    {
        $holds = $this->holds;

        if (! $cart->hasExpiry()) {
            if ($holds !== []) {
                return CartDecision::denied(
                    CartDecision::CART_NEVER_EXPIRES,
                    sprintf(
                        'Cart %s holds %d unit(s) but has no expires_at: the cart stays open '
                        . 'after its seats have gone back on sale.',
                        (string) $cart->id,
                        count($holds)
                    )
                );
            }

            return CartDecision::allowed();
        }

        if ($cart->expiresAt > $sessionStartsAt) {
            return CartDecision::denied(
                CartDecision::OUTLIVES_SESSION,
                sprintf(
                    'Cart %s expires at %s, after the performance starts at %s.',
                    (string) $cart->id,
                    $cart->expiresAt->format('c'),
                    $sessionStartsAt->format('c')
                )
            );
        }

        $earliest = $this->earliestHoldExpiry();
        if ($earliest !== null && $cart->expiresAt > $earliest) {
            return CartDecision::denied(
                CartDecision::OUTLIVES_HOLD,
                sprintf(
                    'Cart %s expires at %s but its earliest hold stops being honoured at %s: '
                    . 'for that gap the cart looks open while its seats are already gone.',
                    (string) $cart->id,
                    $cart->expiresAt->format('c'),
                    $earliest->format('c')
                )
            );
        }

        return CartDecision::allowed();
    }

    /**
     * Audit a cart that was ALREADY written — the only way to catch rows created
     * before these rules existed (or by a direct POST that bypassed them).
     */
    public function compositionDecision(Cart $cart): CartDecision
    {
        $stray = $cart->strayItems();
        if ($stray !== []) {
            $item = $stray[0];

            return CartDecision::denied(
                CartDecision::WRONG_SESSION,
                sprintf(
                    'Cart %s (session %d) holds %d item(s) from other sessions, first is item %d of session %d.',
                    (string) $cart->id,
                    $cart->sessionId,
                    count($stray),
                    $item->inventoryItemId,
                    $item->sessionId
                )
            );
        }

        $incoherent = $cart->incoherentItems();
        if ($incoherent !== []) {
            $item = $incoherent[0];

            return CartDecision::denied(
                CartDecision::INCOHERENT_MONEY,
                sprintf(
                    'Cart %s holds %d line(s) whose stored total disagrees with unit price × quantity, '
                    . 'first is item %d: stored %d, expected %d.',
                    (string) $cart->id,
                    count($incoherent),
                    $item->inventoryItemId,
                    $item->totalPrice->minorUnits(),
                    $item->expectedTotal()->minorUnits()
                )
            );
        }

        return CartDecision::allowed();
    }

    private function earliestHoldExpiry(): ?\DateTimeImmutable
    {
        $earliest = null;
        foreach ($this->holds as $hold) {
            $expiresAt = $hold->expiresAt();
            if ($earliest === null || $expiresAt < $earliest) {
                $earliest = $expiresAt;
            }
        }

        return $earliest;
    }
}
