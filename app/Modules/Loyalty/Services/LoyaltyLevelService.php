<?php

declare(strict_types=1);

namespace Nabilet\Modules\Loyalty\Services;

use Illuminate\Support\Facades\DB;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Domain\LoyaltyTierPolicy;
use Nabilet\Modules\Loyalty\Models\LoyaltyLevel;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;

/**
 * Уровни «Клуба зрителей»: подсчёт оплаченных заказов и выбор/пересчёт уровня.
 *
 * СЕРВИС, А НЕ МОДЕЛЬ
 *   Выбор уровня требует базы (пороги из `loyalty_levels` и COUNT оплаченных
 *   заказов), поэтому логика живёт здесь, а не в `LoyaltyLevel` (тонкая модель)
 *   и не в `LoyaltyTierPolicy` (фреймворк-фри, принимает готовый список). Это
 *   тот же разделённый на три слоя паттерн, что у `BonusService`/`BonusPolicy`.
 *
 * ПЕРЕСЧЁТ — ПРОИЗВОДНОЕ ОТ ЗАКАЗОВ
 *   Уровень не хранится как «факт», а выводится из `COUNT(orders WHERE
 *   status='paid')`. Поэтому возврат/отмена оплаченного заказа опускает уровень
 *   автоматически — никакой отдельной «истории понижений» не нужно.
 */
final class LoyaltyLevelService
{
    /**
     * Все уровни, упорядоченные по возрастанию порога (как ожидает `LoyaltyTierPolicy`).
     *
     * @return list<array{slug: string, name: string, min_paid_orders: int}>
     */
    public function tiers(): array
    {
        return LoyaltyLevel::ordered()->get()->map(
            fn (LoyaltyLevel $level): array => [
                'slug' => $level->slug,
                'name' => $level->name,
                'min_paid_orders' => (int) $level->min_paid_orders,
            ]
        )->all();
    }

    /** Число оплаченных заказов покупателя. */
    public function paidOrdersCount(User $user): int
    {
        // Уровень считается по покупателю ПОПЕРЁК организаций — легитимный
        // кросс-тенантный агрегат (как рекомендации). Глобальный скоуп `Order`
        // здесь не нужен и ломал бы публичный /me/loyalty-level без контекста.
        return app(OrganizationContext::class)->withoutScope(
            fn (): int => Order::query()
                ->where('user_id', $user->getKey())
                ->where('status', OrderStateMachine::PAID)
                ->count()
        );
    }

    /**
     * Пересчитать и сохранить уровень покупателя по числу его оплаченных заказов.
     *
     * Пишет `users.loyalty_level_id` только при смене значения: повторный переход
     * в тот же статус (идемпотентный `markPaid`) не трогает строку. Если уровень
     * не найден (пустой конфиг) — не падает, просто ничего не пишет.
     */
    public function recompute(User $user): void
    {
        $tiers = $this->tiers();
        $chosen = LoyaltyTierPolicy::resolve($this->paidOrdersCount($user), $tiers)
            ?? ($tiers[0] ?? null);

        if ($chosen === null) {
            return;
        }

        $level = LoyaltyLevel::where('slug', $chosen['slug'])->first();

        if ($level === null || (int) $user->loyalty_level_id === (int) $level->id) {
            return;
        }

        DB::table('users')
            ->where('id', $user->getKey())
            ->update(['loyalty_level_id' => $level->id]);
    }

    /**
     * Полный статус уровня для API: текущий уровень, число оплаченных заказов и
     * прогресс до следующего.
     *
     * @return array{
     *     slug: string,
     *     name: string,
     *     min_paid_orders: int,
     *     paid_orders: int,
     *     next_level: array{slug: string, name: string, min_paid_orders: int}|null,
     *     orders_to_next: int
     * }
     */
    public function statusFor(User $user): array
    {
        $tiers = $this->tiers();
        $paid = $this->paidOrdersCount($user);

        $current = LoyaltyTierPolicy::resolve($paid, $tiers)
            ?? ($tiers[0] ?? ['slug' => LoyaltyTierPolicy::START, 'name' => 'Старт', 'min_paid_orders' => 0]);

        $next = null;
        foreach ($tiers as $tier) {
            if ((int) $tier['min_paid_orders'] > (int) $current['min_paid_orders']) {
                $next = $tier;
                break;
            }
        }

        return [
            'slug' => $current['slug'],
            'name' => $current['name'],
            'min_paid_orders' => (int) $current['min_paid_orders'],
            'paid_orders' => $paid,
            'next_level' => $next,
            'orders_to_next' => $next === null ? 0 : max(0, (int) $next['min_paid_orders'] - $paid),
        ];
    }
}
