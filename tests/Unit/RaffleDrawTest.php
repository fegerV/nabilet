<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Loyalty\Domain\RaffleDraw;
use Nabilet\Modules\Loyalty\Domain\RaffleWindow;
use Nabilet\Modules\Loyalty\StateMachines\RaffleStateMachine;
use Nabilet\Tests\Support\TestCase;

/**
 * Розыгрыши (фаза 5): чистые правила тиража, окна и статусов.
 *
 * БЕЗ БАЗЫ И БЕЗ ФРЕЙМВОРКА — тот же путь, что у рефералки
 * (`ReferralCodeTest`): `tests/run.php` не грузит Eloquent, поэтому здесь
 * проверяются ровно те классы, которые обязаны работать везде — алгоритм
 * тиража (`RaffleDraw`), окно набора участников (`RaffleWindow`) и словарь
 * статусов (`RaffleStateMachine`).
 *
 * ПОЧЕМУ ТИРАЖ — САМОЕ ВАЖНОЕ МЕСТО ДЛЯ ЮНИТ-ТЕСТОВ
 *   Результат тиража невозможно перепроверить вручную: победитель либо
 *   выиграл, либо нет. Единственная защита от «а почему он?» — алгоритм,
 *   который воспроизводим при известном источнике случайности. Поэтому ГПСЧ
 *   здесь подставляется: с фиксированной последовательностью тираж
 *   детерминирован, и каждое утверждение ниже — проверка правила, а не
 *   везения.
 */
final class RaffleDrawTest extends TestCase
{
    // ── тираж: без возврата ─────────────────────────────────────────────────

    public function test_each_place_gets_a_distinct_entry(): void
    {
        $entries = [10, 20, 30, 40, 50];
        $places = [1, 2, 3, 4, 5];

        // Источник случайности фиксирован: тираж воспроизводим.
        $winners = RaffleDraw::assign($entries, $places, fn (int $bound): int => $bound - 1);

        $this->assertSame($places, array_keys($winners));
        $this->assertSame(
            count($places),
            count(array_unique(array_values($winners))),
            'one entry must not take two places — the draw is without replacement'
        );
    }

    public function test_entries_are_not_repeated_across_places_with_repeated_rng(): void
    {
        // Источник, который всегда возвращает 0, — крайний случай: без
        // безвозвратной выборки победителем стал бы один и тот же билет на
        // всех местах.
        $winners = RaffleDraw::assign(
            entryIds: ['a', 'b', 'c'],
            places: [1, 2, 3],
            rng: static fn (int $bound): int => 0,
        );

        $this->assertCount(3, $winners);
        $this->assertSame(
            3,
            count(array_unique(array_values($winners))),
            'a degenerate RNG must still hand out distinct entries'
        );
    }

    public function test_places_are_sorted_and_deduplicated(): void
    {
        // Призы могли прийти из формы в произвольном порядке и с дублем:
        // «два первых места» сделали бы результат неопределённым.
        $winners = RaffleDraw::assign(
            entryIds: ['a', 'b', 'c'],
            places: [3, 1, 3, 2],
            rng: static fn (int $bound): int => 0,
        );

        $this->assertSame([1, 2, 3], array_keys($winners));
    }

    public function test_draw_is_reproducible_with_a_fixed_rng(): void
    {
        $entries = [1, 2, 3, 4, 5, 6, 7, 8];
        $places = [1, 2, 3];

        // Детерминированная «последовательность»: уменьшающийся индекс.
        $first = RaffleDraw::assign($entries, $places, static fn (int $bound): int => max(0, $bound - 2));
        $second = RaffleDraw::assign($entries, $places, static fn (int $bound): int => max(0, $bound - 2));

        $this->assertSame($first, $second, 'the same RNG must give the same draw');
    }

    public function test_shuffle_keeps_all_elements(): void
    {
        $items = [1, 2, 3, 4, 5];
        $shuffled = RaffleDraw::shuffle($items, static fn (int $bound): int => 0);

        $this->assertCount(5, $shuffled);
        sort($shuffled);
        $this->assertSame($items, $shuffled);
    }

    public function test_out_of_range_rng_index_is_clamped(): void
    {
        // Сломанный источник случайности не должен молча ломать тираж:
        // индекс зажимается в диапазон, и набор победителей остаётся
        // корректным (просто предсказуемым).
        $winners = RaffleDraw::assign(
            entryIds: ['a', 'b', 'c'],
            places: [1, 2, 3],
            rng: static fn (int $bound): int => 999,
        );

        $this->assertSame(3, count(array_unique(array_values($winners))));
    }

    public function test_draw_without_rng_uses_random_int(): void
    {
        // Дымовой тест дефолтного источника: реальный CSPRNG, а не заглушка.
        $winners = RaffleDraw::assign([1, 2, 3], [1, 2, 3]);

        $this->assertCount(3, $winners);
        $this->assertSame(3, count(array_unique(array_values($winners))));
    }

    // ── окно набора участников ──────────────────────────────────────────────

    public function test_window_covers_paid_orders_inside_and_on_the_border(): void
    {
        $window = new RaffleWindow(
            status: RaffleStateMachine::ACTIVE,
            startsAt: new \DateTimeImmutable('2026-10-01 00:00:00'),
            endsAt: new \DateTimeImmutable('2026-10-31 23:59:59'),
        );

        $this->assertTrue($window->covers(new \DateTimeImmutable('2026-10-15 12:00:00')));
        $this->assertTrue(
            $window->covers(new \DateTimeImmutable('2026-10-01 00:00:00')),
            'the start border is inclusive'
        );
        $this->assertTrue(
            $window->covers(new \DateTimeImmutable('2026-10-31 23:59:59')),
            'the end border is inclusive'
        );
        $this->assertFalse($window->covers(new \DateTimeImmutable('2026-09-30 23:59:59')));
        $this->assertFalse($window->covers(new \DateTimeImmutable('2026-11-01 00:00:00')));
    }

    public function test_null_borders_mean_no_bound(): void
    {
        $window = new RaffleWindow(
            status: RaffleStateMachine::ACTIVE,
            startsAt: null,
            endsAt: null,
        );

        // Заказ, оплаченный до создания розыгрыша, легально участвует в
        // розыгрыше «за всё время»: NULL-граница — не магическая дата.
        $this->assertTrue($window->covers(new \DateTimeImmutable('2020-01-01 00:00:00')));
        $this->assertTrue($window->covers(new \DateTimeImmutable('2030-01-01 00:00:00')));
    }

    public function test_non_active_raffle_never_accepts_entries(): void
    {
        foreach ([RaffleStateMachine::DRAFT, RaffleStateMachine::DRAWN, RaffleStateMachine::CANCELLED] as $status) {
            $window = new RaffleWindow($status, null, null);

            $this->assertFalse(
                $window->covers(new \DateTimeImmutable('2026-10-15 12:00:00')),
                sprintf('status "%s" must not accept entries', $status)
            );
        }
    }

    public function test_window_defaults_to_now(): void
    {
        $window = new RaffleWindow(RaffleStateMachine::ACTIVE, null, new \DateTimeImmutable('2000-01-01'));

        // Конец окна в прошлом: «сейчас» в него не входит.
        $this->assertFalse($window->covers());
    }

    // ── статусы ─────────────────────────────────────────────────────────────

    public function test_terminal_states_have_no_transitions(): void
    {
        $machine = RaffleStateMachine::make();

        $this->assertSame([], $machine->allowedFrom(RaffleStateMachine::DRAWN));
        $this->assertSame([], $machine->allowedFrom(RaffleStateMachine::CANCELLED));
    }

    public function test_active_can_pause_and_be_drawn(): void
    {
        $machine = RaffleStateMachine::make();

        $this->assertTrue($machine->can(RaffleStateMachine::ACTIVE, RaffleStateMachine::DRAWN));
        $this->assertTrue($machine->can(RaffleStateMachine::ACTIVE, RaffleStateMachine::DRAFT));
        $this->assertTrue($machine->can(RaffleStateMachine::DRAFT, RaffleStateMachine::ACTIVE));
        // Тираж необратим: из drawn нет путей никуда.
        $this->assertFalse($machine->can(RaffleStateMachine::DRAWN, RaffleStateMachine::ACTIVE));
    }

    public function test_status_vocabulary_is_closed(): void
    {
        // Словарь обязан совпадать с CHECK `ck_raffles_status` (сплит 022);
        // `tools/verify-state-machines.php` сверяет обратное направление.
        $this->assertSame(
            ['draft', 'active', 'drawn', 'cancelled'],
            RaffleStateMachine::make()->states()
        );
        $this->assertSame('draft', RaffleStateMachine::make()->initial());
    }
}
