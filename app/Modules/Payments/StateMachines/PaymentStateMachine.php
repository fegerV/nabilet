<?php

declare(strict_types=1);

namespace App\Modules\Payments\StateMachines;

use Nabilet\Core\StateMachine\StateMachine;

/**
 * Payment lifecycle (ТЗ §27, §28).
 *
 * Modelled on YooKassa's status vocabulary (`pending`, `waiting_for_capture`,
 * `succeeded`, `canceled`) because it is the first provider, but the names are
 * provider-neutral so a second provider does not require a status translation
 * layer. Providers map onto THIS vocabulary — not the other way round.
 *
 * `waiting_for_capture` exists because two-step payments (authorize, then capture)
 * are the norm for pre-orders and for events with a cancellation policy: money is
 * held on the card but not taken until the organizer confirms.
 *
 * CRITICAL (ТЗ §28): this machine is the guard rail for idempotency. A webhook
 * arriving three times will attempt `succeeded → succeeded`, which this machine
 * rejects — so the handler must treat "already in target state" as success, not as
 * an error. That is what guarantees "three webhooks → one paid order, one ticket
 * issuance".
 *
 * NO `refunded` / `partially_refunded` STATE, ON PURPOSE.
 * ck_payments_status allows exactly five values and refunds are not among them.
 * Refund progress lives on the `refunds` table with its own machine
 * (requested → processing → succeeded / failed), which is the right place for it:
 * a payment can be refunded several times, partially, and each attempt has its own
 * outcome — none of that fits in one column on the payment.
 *
 * If the product ever needs "this payment was fully refunded" as a top-level
 * status, widen ck_payments_status in the spec bundle FIRST. Adding the state here
 * alone would produce a row the database refuses to store.
 */
final class PaymentStateMachine
{
    public const PENDING = 'pending';
    public const WAITING_FOR_CAPTURE = 'waiting_for_capture';
    public const SUCCEEDED = 'succeeded';
    /**
     * `canceled`, ONE L — this one is deliberate and is the exception in the
     * codebase. ck_payments_status spells it that way because the vocabulary is
     * the provider's (YooKassa), and translating provider statuses into our own
     * spelling would create a mapping that has to be maintained forever.
     *
     * Everything else (orders, sessions, events, tickets) uses `cancelled`.
     */
    public const CANCELED = 'canceled';
    public const FAILED = 'failed';

    public static function make(): StateMachine
    {
        $machine = StateMachine::define(
            name: 'Payment',
            initial: self::PENDING,
            states: [
                self::PENDING,
                self::WAITING_FOR_CAPTURE,
                self::SUCCEEDED,
                self::CANCELED,
                self::FAILED,
            ],
            transitions: [
                self::PENDING => [
                    self::WAITING_FOR_CAPTURE,
                    self::SUCCEEDED,
                    self::CANCELED,
                    self::FAILED,
                ],
                self::WAITING_FOR_CAPTURE => [self::SUCCEEDED, self::CANCELED, self::FAILED],
                self::SUCCEEDED => [],
                self::CANCELED => [],
                self::FAILED => [],
            ],
            terminal: [self::SUCCEEDED, self::CANCELED, self::FAILED],
        );

        return $machine;
    }

    /** @return list<string> statuses meaning "money has been received" */
    public static function moneyReceived(): array
    {
        return [self::SUCCEEDED];
    }
}
