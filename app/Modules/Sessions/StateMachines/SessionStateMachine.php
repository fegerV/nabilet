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

    /**
     * Все состояния жизненного цикла.
     *
     * ЗАЧЕМ. `SessionController` держал свой список для правила `in:` —
     * `scheduled,on_sale,held,completed,cancelled`. Этот список разошёлся с
     * машиной в ОБЕ стороны:
     *
     *  - `held` в машине и в `ck_sessions_status` нет вовсе. Это состояние
     *    позиции инвентаря (`InventoryItemStateMachine::HELD`), попавшее сюда
     *    копированием из соседнего словаря. Запись с ним проходила валидацию и
     *    падала на MySQL — 500 вместо 422.
     *  - `draft`, `sold_out` и `closed` в списке отсутствуют, хотя машина их
     *    знает, а `sold_out` и `closed` — обязательные состояния продаж
     *    (`on_sale ⇄ sold_out → closed`, см. `docs/STATE-MACHINES.md`).
     *    Администратор не мог выставить их через API.
     *
     * Список состояний — часть машины, а не деталь контроллера. Гейт
     * `tools/verify-state-machines.php` сверяет машину с CHECK-констрейнтом,
     * но не с правилами валидации: правило валидации — тоже потребитель
     * словаря, и он обязан спрашивать его здесь.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::DRAFT,
            self::SCHEDULED,
            self::ON_SALE,
            self::SOLD_OUT,
            self::CLOSED,
            self::COMPLETED,
            self::CANCELLED,
        ];
    }
}
