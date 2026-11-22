<?php

declare(strict_types=1);

namespace Tests\Unit;

use Nabilet\Modules\Loyalty\Domain\AchievementPolicy;
use PHPUnit\Framework\TestCase;

final class AchievementPolicyTest extends TestCase
{
    private function evaluate(array $visits): array
    {
        $catalog = json_decode(file_get_contents(__DIR__.'/../../resources/data/club-achievements.json'), true);
        return array_column(AchievementPolicy::evaluate($visits, $catalog), null, 'id');
    }

    private function visit(int $id, array $overrides = []): array
    {
        return array_replace([
            'session_id' => $id, 'used_at' => sprintf('2026-11-%02dT16:00:00Z', $id),
            'local_start' => sprintf('2026-11-%02d 21:00:00', $id),
            'category' => 'concerts', 'venue_id' => 1, 'genres' => ['Heavy Metal'],
            'artists' => [42],
        ], $overrides);
    }

    public function test_empty_history_does_not_award_demo_medals(): void
    {
        $rows = $this->evaluate([]);
        $this->assertCount(15, $rows);
        $this->assertSame('progress', $rows['legend']['status']);
        $this->assertSame('hidden', $rows['nightlife']['status']);
        $this->assertSame(0, $rows['first-step']['progress']['current']);
    }

    public function test_duplicate_admissions_count_once_and_award_date_is_threshold_date(): void
    {
        $rows = $this->evaluate([$this->visit(3), $this->visit(1), $this->visit(1), $this->visit(2)]);
        $this->assertSame(3, $rows['legend']['progress']['current']);
        $this->assertSame('earned', $rows['rock-alive']['status']);
        $this->assertSame('2026-11-03T16:00:00Z', $rows['rock-alive']['earnedAt']);
        $this->assertSame('earned', $rows['same-wave']['status']);
        $this->assertSame(1, $rows['all-genres']['progress']['current']);
    }

    public function test_monthly_progress_is_best_calendar_month_and_night_uses_local_start(): void
    {
        $rows = $this->evaluate([
            $this->visit(1, ['local_start' => '2026-10-31 22:00:00']),
            $this->visit(2, ['local_start' => '2026-11-01 19:00:00']),
            $this->visit(3, ['local_start' => '2026-11-02 19:00:00']),
        ]);
        $this->assertSame(2, $rows['culture-marathon']['progress']['current']);
        $this->assertFalse($rows['nightlife']['hidden']);
        $this->assertSame('earned', $rows['nightlife']['status']);
        $rows = $this->evaluate([$this->visit(1, ['local_start' => null, 'used_at' => '2026-11-01T23:00:00Z'])]);
        $this->assertSame('hidden', $rows['nightlife']['status']);
    }

    public function test_theatre_requires_three_distinct_categories_and_venues(): void
    {
        $visits = [];
        foreach (['comedy', 'drama', 'musical'] as $i => $category) {
            $visits[] = $this->visit($i + 1, ['category' => $category, 'genres' => [], 'artists' => [], 'venue_id' => $i + 1]);
        }
        $rows = $this->evaluate($visits);
        $this->assertSame('earned', $rows['backstage']['status']);
        $this->assertSame('earned', $rows['stage-expert']['status']);
        $this->assertSame(0, $rows['meloman']['progress']['current']);
        $this->assertSame('progress', $this->evaluate(array_slice($visits, 0, 2))['backstage']['status']);
    }

    public function test_unknown_metadata_does_not_invent_genres_or_artists(): void
    {
        $rows = $this->evaluate([$this->visit(1, ['category' => '', 'genres' => ['unknown'], 'artists' => []])]);
        $this->assertSame('earned', $rows['first-step']['status']);
        $this->assertSame(0, $rows['meloman']['progress']['current']);
        $this->assertSame(0, $rows['open-talents']['progress']['current']);
    }

    public function test_every_medal_is_reachable_with_qualifying_history(): void
    {
        $visits = [];
        for ($i = 1; $i <= 25; $i++) {
            $overrides = ['local_start' => sprintf('2026-11-%02d 22:00:00', $i)];
            if ($i >= 4 && $i <= 8) {
                $overrides += ['category' => ['comedy', 'drama', 'musical'][$i % 3], 'genres' => [], 'artists' => [], 'venue_id' => $i];
            } elseif ($i >= 9) {
                $overrides += ['category' => 'concerts', 'genres' => [['Jazz', 'Pop', 'Classical'][$i % 3]], 'artists' => [$i]];
            }
            $visits[] = $this->visit($i, $overrides);
        }
        $rows = $this->evaluate($visits);
        $this->assertSame(array_fill(0, 15, 'earned'), array_column($rows, 'status'));
    }
}
