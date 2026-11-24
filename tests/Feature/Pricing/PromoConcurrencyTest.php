<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Pricing\Domain\PromoEvaluation;
use Nabilet\Modules\Orders\Models\PromoCode;
use Nabilet\Modules\Pricing\Services\PromoCodeService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Лимит промокода — гонка D-6 из аудита payments.
 *
 * `evaluateForCart()` читает счётчики до транзакции, поэтому проверка там не
 * защищает от параллельных чекаутов. Настоящая защита — conditional UPDATE
 * `WHERE redemptions_count < max_redemptions`. Тест внедряет конкурента ровно
 * между проверкой per_user_limit и этим UPDATE и требует `PROMO_CODE_EXHAUSTED`.
 */
final class PromoConcurrencyTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    public function test_a_competing_redemption_cannot_exceed_max_redemptions(): void
    {
        [$organizationId, , , $inventoryItemId] = $this->seedSellableSeat(2);
        $promoId = $this->seedPromo($organizationId, 1);
        $userId = $this->seedUser($organizationId);

        $promo = app(OrganizationContext::class)->withoutScope(
            fn (): PromoCode => PromoCode::query()->findOrFail($promoId)
        );
        $orders = $this->app->make(OrderService::class);
        $order = $orders->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'promo-race@example.com',
            'items' => [['inventory_item_id' => $inventoryItemId, 'quantity' => 1]],
        ]);
        $evaluation = PromoEvaluation::accepted(Money::of(500_000, 'RUB'));

        $injected = false;
        $competitorAffected = null;

        DB::listen(function ($query) use (&$injected, &$competitorAffected, $promoId): void {
            if ($injected || $query->connectionName !== 'mysql') {
                return;
            }
            $sql = strtolower($query->sql);
            // per_user_limit перечитывается под локом — последний шов до UPDATE.
            if (! str_contains($sql, 'promo_code_redemptions') || ! str_contains($sql, 'for update')) {
                return;
            }
            $injected = true;
            $competitorAffected = DB::table('promo_codes')
                ->where('id', $promoId)
                ->whereColumn('redemptions_count', '<', 'max_redemptions')
                ->update(['redemptions_count' => DB::raw('redemptions_count + 1')]);
        });

        try {
            app(PromoCodeService::class)->redeem($promo, $order, $evaluation, $userId);
            self::fail('конкурент уже исчерпал код — второй redeem обязан отказать');
        } catch (ConflictError $error) {
            self::assertSame('PROMO_CODE_EXHAUSTED', $error->errorCode);
        }

        self::assertTrue($injected, 'конкурент должен был стартовать до conditional UPDATE');
        self::assertSame(1, $competitorAffected);
        self::assertSame(1, (int) DB::table('promo_codes')->where('id', $promoId)->value('redemptions_count'));
        self::assertSame(0, (int) DB::table('promo_code_redemptions')->where('promo_code_id', $promoId)->count());
    }

    public function test_a_second_redemption_of_a_single_use_code_is_rejected(): void
    {
        [$organizationId, , , $inventoryItemId] = $this->seedSellableSeat(2);
        $promoId = $this->seedPromo($organizationId, 1);
        $userId = $this->seedUser($organizationId);
        $promo = app(OrganizationContext::class)->withoutScope(
            fn (): PromoCode => PromoCode::query()->findOrFail($promoId)
        );
        $orders = $this->app->make(OrderService::class);
        $evaluation = PromoEvaluation::accepted(Money::of(500_000, 'RUB'));
        $service = app(PromoCodeService::class);

        foreach ([1, 2] as $index) {
            $order = $orders->createOrder([
                'organization_id' => $organizationId,
                'customer_email' => "promo-{$index}@example.com",
                'items' => [['inventory_item_id' => $inventoryItemId, 'quantity' => 1]],
            ]);
            if ($index === 1) {
                $service->redeem($promo, $order, $evaluation, $userId);
                continue;
            }
            try {
                $service->redeem($promo->refresh(), $order, $evaluation, $userId);
                self::fail('второй redeem одноразового кода обязан отказать');
            } catch (ConflictError $error) {
                self::assertSame('PROMO_CODE_EXHAUSTED', $error->errorCode);
            }
        }

        self::assertSame(1, (int) DB::table('promo_codes')->where('id', $promoId)->value('redemptions_count'));
        self::assertSame(1, (int) DB::table('promo_code_redemptions')->where('promo_code_id', $promoId)->count());
    }

    private function seedPromo(int $organizationId, int $maxRedemptions): int
    {
        return (int) DB::table('promo_codes')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'code' => 'RACE' . Str::upper(Str::random(6)),
            'discount_type' => 'percent',
            'value_amount' => 0,
            'value_percent' => 10,
            'currency' => 'RUB',
            'scope' => 'all',
            'min_order_amount' => 0,
            'per_user_limit' => 1,
            'max_redemptions' => $maxRedemptions,
            'redemptions_count' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedUser(int $organizationId): int
    {
        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));
        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => now(),
        ]);

        return $userId;
    }
}
