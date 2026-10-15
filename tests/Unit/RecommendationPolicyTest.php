<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Loyalty\Domain\RecommendationPolicy;
use Nabilet\Tests\Support\TestCase;

/**
 * Правило ранжирования рекомендаций — там, где порядок «Рекомендуем» решается
 * без базы.
 *
 * ЧТО ИМЕННО ЗАЩИЩАЕТСЯ
 *   Тесты держат три свойства, от которых зависит, будет ли полка выглядеть
 *   осмысленно:
 *     1. СИЛА СИГНАЛА. Категория, тронутая чаще, идёт выше.
 *     2. СРОК. При равном сигнале раньше показывается то, что можно посмотреть
 *        скорее; кандидат без даты — в конец, а не в начало.
 *     3. ДЕТЕРМИНИЗМ. Равные кандидаты не должны менять порядок от запроса к
 *        запросу — иначе витрина «дрожит» на каждом обновлении.
 *   Плюс границы лимита: неположительный — это «по умолчанию», а не пустая
 *   выдача; слишком большой зажимается потолком.
 *
 * Класс исполняется `php tests/run.php` без `vendor/` и без MySQL — это и есть
 * причина, по которой правило живёт в `Domain/` (см. `tools/verify-purity.php`).
 */
final class RecommendationPolicyTest extends TestCase
{
    /**
     * Дескриптор кандидата в форме, которую принимает правило.
     *
     * @return array{event_id:int, category_id:int|null, starts_at_ts:int|null}
     */
    private function candidate(int $eventId, ?int $categoryId, ?int $startsAtTs = null): array
    {
        return ['event_id' => $eventId, 'category_id' => $categoryId, 'starts_at_ts' => $startsAtTs];
    }

    // ── лимит ────────────────────────────────────────────────────────────────

    public function test_zero_limit_falls_back_to_default(): void
    {
        $this->assertSame(RecommendationPolicy::DEFAULT_LIMIT, RecommendationPolicy::clampLimit(0));
    }

    public function test_negative_limit_falls_back_to_default(): void
    {
        // Отрицательное число пришло из строки запроса — это «не задано», а не
        // «покажи ничего»: пустая полка из-за опечатки в URL была бы ложью.
        $this->assertSame(RecommendationPolicy::DEFAULT_LIMIT, RecommendationPolicy::clampLimit(-7));
    }

    public function test_limit_within_range_is_kept(): void
    {
        $this->assertSame(1, RecommendationPolicy::clampLimit(1));
        $this->assertSame(12, RecommendationPolicy::clampLimit(12));
    }

    public function test_limit_above_ceiling_is_capped(): void
    {
        // «Рекомендуем» — полка, а не второй каталог: один запрос не должен
        // тянуть всю афишу.
        $this->assertSame(RecommendationPolicy::MAX_LIMIT, RecommendationPolicy::clampLimit(1000));
    }

    // ── ранжирование ─────────────────────────────────────────────────────────

    public function test_empty_candidates_yield_empty(): void
    {
        $this->assertSame([], RecommendationPolicy::rank([1 => 3], [], 8));
    }

    public function test_candidate_without_a_taste_signal_is_excluded(): void
    {
        // Категории 2 нет в профиле — вес 0. Показывать это в «Рекомендуем»
        // значило бы выдать случайный каталог под видом персонального.
        $result = RecommendationPolicy::rank(
            [1 => 5],
            [$this->candidate(10, 1, 100), $this->candidate(11, 2, 50)],
            8
        );

        $this->assertSame([10], $result);
    }

    public function test_candidate_with_null_category_is_excluded(): void
    {
        // Мероприятие без категории профилю не сопоставить — сигнала нет.
        $result = RecommendationPolicy::rank(
            [1 => 5],
            [$this->candidate(10, null, 100), $this->candidate(11, 1, 200)],
            8
        );

        $this->assertSame([11], $result);
    }

    public function test_higher_weight_ranks_first(): void
    {
        // Категория 1 встречалась в профиле трижды, категория 2 — один раз.
        $result = RecommendationPolicy::rank(
            [1 => 3, 2 => 1],
            [$this->candidate(10, 2, 100), $this->candidate(11, 1, 999)],
            8
        );

        $this->assertSame([11, 10], $result);
    }

    public function test_equal_weight_prefers_the_earlier_session(): void
    {
        // Одинаковый вес — решает срок: раньше начинается, раньше в выдаче.
        $result = RecommendationPolicy::rank(
            [1 => 2],
            [$this->candidate(10, 1, 500), $this->candidate(11, 1, 100)],
            8
        );

        $this->assertSame([11, 10], $result);
    }

    public function test_candidate_without_a_date_ranks_after_dated_ones(): void
    {
        // Пустая дата не повод показать раньше того, что уже на календаре.
        $result = RecommendationPolicy::rank(
            [1 => 2],
            [$this->candidate(10, 1, null), $this->candidate(11, 1, 900)],
            8
        );

        $this->assertSame([11, 10], $result);
    }

    public function test_equal_weight_and_date_break_ties_by_event_id(): void
    {
        // Детерминизм: два неотличимых кандидата не должны меняться местами от
        // запроса к запросу. Меньший id — раньше.
        $result = RecommendationPolicy::rank(
            [1 => 1],
            [$this->candidate(30, 1, 100), $this->candidate(20, 1, 100)],
            8
        );

        $this->assertSame([20, 30], $result);
    }

    public function test_limit_truncates_the_tail(): void
    {
        $result = RecommendationPolicy::rank(
            [1 => 1],
            [
                $this->candidate(10, 1, 100),
                $this->candidate(11, 1, 200),
                $this->candidate(12, 1, 300),
            ],
            2
        );

        $this->assertSame([10, 11], $result);
    }

    public function test_ranking_is_stable_when_candidates_arrive_unsorted(): void
    {
        // Порядок входа не должен влиять на выход: правило сортирует само.
        $candidates = [
            $this->candidate(12, 1, 300),
            $this->candidate(10, 1, 100),
            $this->candidate(11, 1, 200),
        ];

        $this->assertSame([10, 11, 12], RecommendationPolicy::rank([1 => 1], $candidates, 8));
    }
}
