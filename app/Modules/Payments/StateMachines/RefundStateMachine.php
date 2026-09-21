<?php

declare(strict_types=1);

namespace App\Modules\Payments\StateMachines;

use Nabilet\Core\StateMachine\StateMachine;

/**
 * Refund lifecycle (ТЗ §85).
 *
 * Refunds are asynchronous: the provider accepts the request, processes it, and
 * reports the outcome by webhook. Modelling `processing` as a distinct state is
 * what stops the system from telling a customer "refunded" before the money has
 * actually moved.
 *
 * `failed → processing` is legal so a transient provider error can be retried
 * without the operator creating a duplicate refund request.
 */
final class RefundStateMachine
{
    public const REQUESTED = 'requested';
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';

    public static function make(): StateMachine
    {
        return StateMachine::define(
            name: 'Refund',
            initial: self::REQUESTED,
            states: [
                self::REQUESTED,
                self::PROCESSING,
                self::SUCCEEDED,
                self::FAILED,
            ],
            transitions: [
                self::REQUESTED => [self::PROCESSING, self::SUCCEEDED, self::FAILED],
                self::PROCESSING => [self::SUCCEEDED, self::FAILED],
                // retry after a transient provider failure
                self::FAILED => [self::PROCESSING],
                self::SUCCEEDED => [],
            ],
            terminal: [self::SUCCEEDED],
        );
    }
}
