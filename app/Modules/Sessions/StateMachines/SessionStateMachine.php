<?php

declare(strict_types=1);

namespace App\Modules\Sessions\StateMachines;

use Nabilet\Core\StateMachine\StateMachine;

/**
 * Session (occurrence) lifecycle (ТЗ §13).
 *
 * A session is one dated performance of an event. Sales state is deliberately
 * separate from the *sales window* (`sales_start`/`sales_end`): the window is a
 * time-based gate, `on_sale` is the operational switch an organizer flips to stop
 * selling immediately (weather, artist illness) without touching the schedule.
 *
 * `closed` and `completed` are two different things and the schema keeps them
 * apart (ck_sessions_status): `closed` means selling has stopped — the box office
 * is shut, but the performance has not happened yet. `completed` means it has.
 * Conflating them would make "can I still sell?" and "did this happen?" the same
 * question, and they are not.
 *
 * `completed` and `cancelled` are terminal — a session that happened cannot be
 * un-happened, and its inventory is historical record.
 *
 * SPELLING: sessions use `cancelled` (two L), matching ck_sessions_status. Only
 * PAYMENTS use the one-L `canceled`, because that is YooKassa's vocabulary and
 * the provider names are not ours to change. `tools/verify-state-machines.php`
 * pins every state here against the schema so this cannot drift again.
 */
final class SessionStateMachine
{
    public const DRAFT = 'draft';
    public const SCHEDULED = 'scheduled';
    public const ON_SALE = 'on_sale';
    public const SOLD_OUT = 'sold_out';
    public const CLOSED = 'closed';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

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
                self::CLOSED,
                self::COMPLETED,
                self::CANCELLED,
            ],
            transitions: [
                self::DRAFT => [self::SCHEDULED, self::ON_SALE, self::CANCELLED],
                self::SCHEDULED => [self::ON_SALE, self::DRAFT, self::CANCELLED],
                // sold_out can reopen if a hold expires or a ticket is refunded
                self::ON_SALE => [self::SOLD_OUT, self::CLOSED, self::CANCELLED],
                self::SOLD_OUT => [self::ON_SALE, self::CLOSED, self::CANCELLED],
                // sales shut, then the performance happens
                self::CLOSED => [self::COMPLETED, self::CANCELLED],
                self::COMPLETED => [],
                self::CANCELLED => [],
            ],
            terminal: [self::COMPLETED, self::CANCELLED],
        );
    }

    /** @return list<string> statuses in which tickets can still be sold */
    public static function sellable(): array
    {
        return [self::ON_SALE];
    }
}
