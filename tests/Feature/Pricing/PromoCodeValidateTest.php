<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Cart\Services\CartItemService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Публичная проверка промокода: `POST /api/v1/promo-codes/validate`.
 *
 * ЧТО БЫЛО СЛОМАНО. Эндпоинт публичный (покупатель проверяет код до входа, с
 * одной анонимной корзиной), но организацию брал из `OrganizationContext`, а тот
 * у гостя пуст по определению: `ResolveOrganizationContext` заполняет его только
 * из аутентифицированного пользователя или host-маппинга. Итог — 500
 * `TenantContextMissingError` на штатный запрос витрины.
 *
 * Теперь организация выводится из КОРЗИНЫ (`carts.session_id → sessions.event_id
 * → events.organization_id`). Это не догадка: корзина создаётся сервером при
 * выборе места, и её сеанс уже привязан к организатору.
 *
 * Проверяем и обратную сторону: без корзины организация неизвестна, поэтому
 * ответ — честный 422 с указанием поля, а не 500 и не «первая организация в БД»
 * (последнее было бы утечкой чужих скидок между организаторами).
 */
final class PromoCodeValidateTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private CartItemService $items;

    protected function setUp(): void
    {
        parent::setUp();

        $this->items = $this->app->make(CartItemService::class);
    }

    /**
     * Гостевая корзина с одним местом.
     *
     * @return array{0: string, 1: int} [cartToken, organizationId]
     */
    private function guestCart(): array
    {
        [$organizationId, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 3);
        $token = 'promo-cart-' . Str::random(12);

        $this->items->addItem((string) $sessionId, $inventoryItemId, 1, $token);

        return [$token, $organizationId];
    }

    private function promoCode(int $organizationId, string $code, float $percent = 10.0): void
    {
        DB::table('promo_codes')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'code' => $code,
            'discount_type' => 'percent',
            'value_amount' => 0,
            'value_percent' => $percent,
            'currency' => 'RUB',
            'scope' => 'all',
            'min_order_amount' => 0,
            'per_user_limit' => 1,
            'redemptions_count' => 0,
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    public function test_a_guest_validates_a_code_against_their_cart(): void
    {
        [$token, $organizationId] = $this->guestCart();
        $this->promoCode($organizationId, 'WELCOME10');

        $response = $this->postJson('/api/v1/promo-codes/validate', [
            'code' => 'WELCOME10',
            'cart_token' => $token,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.valid', true);
        $this->assertGreaterThan(0, (int) $response->json('data.discount_amount'));
    }

    public function test_a_guest_without_a_cart_gets_a_clear_422_not_a_500(): void
    {
        $response = $this->postJson('/api/v1/promo-codes/validate', ['code' => 'WELCOME10']);

        // Именно 422: контракт `validatePromoCode` описывает на этой операции
        // ValidationError, а не внутреннюю ошибку. Клиент должен узнать, какого
        // поля не хватает, а не «что-то пошло не так».
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $response->assertJsonStructure(['error' => ['details' => ['fields' => ['cart_id']]]]);
    }

    public function test_an_unknown_code_is_answered_as_not_applicable(): void
    {
        [$token] = $this->guestCart();

        $response = $this->postJson('/api/v1/promo-codes/validate', [
            'code' => 'NOPE',
            'cart_token' => $token,
        ]);

        // Не 404: иначе эндпоинт стал бы оракулом «существует ли такой код».
        $response->assertOk();
        $response->assertJsonPath('data.valid', false);
        $response->assertJsonPath('data.rejection_reason', 'not_found');
    }

    public function test_a_code_of_another_organization_is_not_applicable(): void
    {
        [$token] = $this->guestCart();

        // Код принадлежит ЧУЖОЙ организации: корзина гостя указывает на первую,
        // значит код второй организации применяться не должен.
        $otherOrganizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Другая организация',
            'slug' => 'other-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->promoCode($otherOrganizationId, 'FOREIGN10');

        $response = $this->postJson('/api/v1/promo-codes/validate', [
            'code' => 'FOREIGN10',
            'cart_token' => $token,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.valid', false);
        $response->assertJsonPath('data.rejection_reason', 'not_found');
    }
}
