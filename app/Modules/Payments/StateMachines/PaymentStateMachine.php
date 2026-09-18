<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\StateMachines;

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
 */
final class PaymentStateMachine
{
    public const PENDING = 'pending';
    public const WAITING_FOR_CAPTURE = 'waiting_for_capture';
    public const SUCCEEDED = 'succeeded';
    public const CANCELED = 'canceled';
    public const FAILED = 'failed';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    public const REFUNDED = 'refunded';

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
                self::PARTIALLY_REFUNDED,
                self::REFUNDED,
            ],
            transitions: [
                self::PENDING => [
                    self::WAITING_FOR_CAPTURE,
                    self::SUCCEEDED,
                    self::CANCELED,
                    self::FAILED,
                ],
                self::WAITING_FOR_CAPTURE => [self::SUCCEEDED, self::CANCELED, self::FAILED],
                self::SUCCEEDED => [self::PARTIALLY_REFUNDED, self::REFUNDED],
                self::PARTIALLY_REFUNDED => [self::REFUNDED],
                self::CANCELED => [],
                self::FAILED => [],
                self::REFUNDED => [],
            ],
            terminal: [self::CANCELED, self::FAILED, self::REFUNDED],
        );

        $machine->guard(self::PARTIALLY_REFUNDED, self::REFUNDED, static function (array $ctx): bool {
            $amount = $ctx['amount_minor'] ?? null;
            $refunded = $ctx['refunded_minor'] ?? null;

            return is_int($amount) && is_int($refunded) && $amount > 0 && $refunded >= $amount;
        });

        $machine->guard(self::SUCCEEDED, self::REFUNDED, static function (array $ctx): bool {
            $amount = $ctx['amount_minor'] ?? null;
            $refunded = $ctx['refunded_minor'] ?? null;

            return is_int($amount) && is_int($refunded) && $amount > 0 && $refunded >= $amount;
        });

        return $machine;
    }

    /** @return list<string> statuses meaning "money has been received" */
    public static function moneyReceived(): array
    {
        return [self::SUCCEEDED, self::PARTIALLY_REFUNDED, self::REFUNDED];
    }
}
