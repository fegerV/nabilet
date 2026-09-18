<?php

declare(strict_types=1);

namespace Nabilet\Modules\Sessions\StateMachines;

use Nabilet\Core\StateMachine\StateMachine;

/**
 * Session (occurrence) lifecycle (ТЗ §13).
 *
 * A session is one dated performance of an event. Sales state is deliberately
 * separate from the *sales window* (`sales_start`/`sales_end`): the window is a
 * time-based gate, `on_sale` is the operational switch an organizer flips to stop
 * selling immediately (weather, artist illness) without touching the schedule.
 *
 * `finished` and `canceled` are terminal — a session that happened cannot be
 * un-happened, and its inventory is historical record.
 */
final class SessionStateMachine
{
    public const DRAFT = 'draft';
    public const SCHEDULED = 'scheduled';
    public const ON_SALE = 'on_sale';
    public const SOLD_OUT = 'sold_out';
    public const FINISHED = 'finished';
    public const CANCELED = 'canceled';

    public static function make(): StateMachine
    {
        return StateMachine::define(
            name: 'Session',
            initial: self::DRAFT,
            states: [
                self::DRAFT,
                self::SCHEDULED,
                self::ON_SALE,
                self::SOLD_OUT,
                self::FINISHED,
                self::CANCELED,
            ],
            transitions: [
                self::DRAFT => [self::SCHEDULED, self::ON_SALE, self::CANCELED],
                self::SCHEDULED => [self::ON_SALE, self::DRAFT, self::CANCELED],
                // sold_out can reopen if a hold expires or a ticket is refunded
                self::ON_SALE => [self::SOLD_OUT, self::FINISHED, self::CANCELED],
                self::SOLD_OUT => [self::ON_SALE, self::FINISHED, self::CANCELED],
                self::FINISHED => [],
                self::CANCELED => [],
            ],
            terminal: [self::FINISHED, self::CANCELED],
        );
    }
}
