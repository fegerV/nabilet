<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Loyalty\Domain\LoyaltyTierPolicy;
use Nabilet\Tests\Support\TestCase;

/**
 * Правила выбора уровня «Клуба зрителей» — там, где уровень зависит от заказов.
 *
 * Тесты проверяют не арифметику (её нет), а то, что функция выбора держит
 * границы порогов: на самом низком пороге — Старт, ровно на границе «друг» —
 * уже «друг», между порогами — самый верхний достигнутый, на вершине — VIP без
 * следующего. Пустой список уровней — это null, а не «Старт по умолчанию»:
 * пустой конфиг не должен притворяться уровнем.
 *
 * Класс исполняется `php tests/run.php` без `vendor/` и без базы — это и есть
 * причина, по которой правило живёт в `Domain/` (см. `tools/verify-purity.php`).
 */
final class LoyaltyTierPolicyTest extends TestCase
{
    /** Три уровня из сид-строк миграции `020_loyalty_levels`, по возрастанию. */
    private function tiers(): array
    {
        return [
            ['slug' => LoyaltyTierPolicy::START, 'name' => 'Старт', 'min_paid_orders' => 0],
            ['slug' => LoyaltyTierPolicy::FRIEND, 'name' => 'Друг', 'min_paid_orders' => 3],
            ['slug' => LoyaltyTierPolicy::VIP, 'name' => 'VIP', 'min_paid_orders' => 10],
        ];
    }

    public function test_zero_orders_resolve_to_start(): void
    {
        $chosen = LoyaltyTierPolicy::resolve(0, $this->tiers());

        $this->assertSame(LoyaltyTierPolicy::START, $chosen['slug']);
    }

    public function test_below_the_first_threshold_is_still_start(): void
    {
        // 2 заказа — ещё ниже порога «друга» (3), поэтому Старт.
        $this->assertSame(LoyaltyTierPolicy::START, LoyaltyTierPolicy::resolve(2, $this->tiers())['slug']);
    }

    public function test_exactly_on_the_friend_threshold_is_friend(): void
    {
        // Граница — это достижение уровня, а не «ещё нет»: 3 заказа → «друг».
        $this->assertSame(LoyaltyTierPolicy::FRIEND, LoyaltyTierPolicy::resolve(3, $this->tiers())['slug']);
    }

    public function test_between_thresholds_keeps_the_highest_reached(): void
    {
        // 7 заказов: выше «друга» (3), но ниже VIP (10) — остаёмся «другом».
        $this->assertSame(LoyaltyTierPolicy::FRIEND, LoyaltyTierPolicy::resolve(7, $this->tiers())['slug']);
    }

    public function test_exactly_on_the_vip_threshold_is_vip(): void
    {
        $this->assertSame(LoyaltyTierPolicy::VIP, LoyaltyTierPolicy::resolve(10, $this->tiers())['slug']);
    }

    public function test_above_the_highest_threshold_stays_vip(): void
    {
        // Сколько бы заказов ни было сверх 10 — выше VIP нет.
        $this->assertSame(LoyaltyTierPolicy::VIP, LoyaltyTierPolicy::resolve(999, $this->tiers())['slug']);
    }

    public function test_an_empty_tier_list_resolves_to_null_not_start(): void
    {
        // Пустой конфиг — не «Старт по умолчанию», а «уровень не определён»:
        // сервис сам подставит Старт, но правило не должно врать о существовании
        // уровня, которого нет в базе.
        $this->assertNull(LoyaltyTierPolicy::resolve(5, []));
    }

    public function test_non_ascending_input_is_resolved_by_loops_not_by_sort(): void
    {
        // `resolve()` не сортирует вход: перезапись при каждом прохождении порога
        // должна дать «самый верхний достигнутый» даже на перепутанном списке.
        $shuffled = [
            ['slug' => LoyaltyTierPolicy::VIP, 'name' => 'VIP', 'min_paid_orders' => 10],
            ['slug' => LoyaltyTierPolicy::START, 'name' => 'Старт', 'min_paid_orders' => 0],
            ['slug' => LoyaltyTierPolicy::FRIEND, 'name' => 'Друг', 'min_paid_orders' => 3],
        ];

        $this->assertSame(LoyaltyTierPolicy::FRIEND, LoyaltyTierPolicy::resolve(3, $shuffled)['slug']);
    }
}
