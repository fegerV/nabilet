<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Services\LoyaltySettingsService;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Ранний доступ клуба (фаза 3b): настройка в админке и гейт на корзине.
 *
 * СКВОЗНОЙ ПУТЬ, КОТОРЫЙ ЗДЕСЬ ЗАЩИЩАЕТСЯ
 *   Публичные пути корзины не закрыты `auth:api`, поэтому покупателя пришлось
 *   назвать явным гвардом (`$request->user('api')` в `CartController`). Без
 *   теста с настоящим Bearer-заголовком ранний доступ молча не работал бы для
 *   всего магазина: клубный уровень есть, а гейт видит гостя.
 *
 *   1. Настройка защищена — только сотрудник; значения валидируются.
 *   2. Гейт: сеанс в статусе `on_sale` и публичный старт завтра →
 *      гость 422, клубный покупатель (3 оплаченных) 201.
 *   3. Порог и выключатель работают: уровень ниже порога и выключенная
 *      настройка дают тот же 422.
 */
class EarlyAccessTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private function earlyAccessSettings(bool $enabled, int $hours = 48, string $minLevelSlug = 'friend'): void
    {
        app(LoyaltySettingsService::class)->save($enabled, $hours, $minLevelSlug);
    }

    private function user(string $email): User
    {
        $now = now();

        $id = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return User::query()->findOrFail($id);
    }

    /**
     * Токен выдаётся напрямую, а не через `/auth/*`: гейту важен только факт
     * вошедшего покупателя, а форма входа проверяется своими тестами.
     */
    private function tokenFor(User $user): string
    {
        return $this->app->make(SessionIssuer::class)->issue($user->id);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Сеанс `on_sale`, у которого продажи откроются ЗАВТРА и закроются через
     * две недели. Сейчас (до старта) его не купить — ровно то окно, внутри
     * которого и проверяется ранний доступ.
     */
    private function sessionOpeningTomorrow(): int
    {
        [, , $sessionId] = $this->seedSellableSeat(available: 2);

        DB::table('sessions')->where('id', $sessionId)->update([
            'sales_start_at' => now()->addDay()->toDateTimeString(),
            'sales_end_at' => now()->addWeeks(2)->toDateTimeString(),
        ]);

        return $sessionId;
    }

    /** Оплаченный заказ — по образцу `LoyaltyLevelTest::paidOrderFor()`. */
    private function paidOrderFor(int $userId): void
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);

        $service = app(OrderService::class);

        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $service->markAwaitingPayment($order);
        $service->markPaid($order->fresh());
    }

    private function staffUser(): User
    {
        $now = now();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Staff Org',
            'slug' => 'staff-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $user = $this->user('staff@example.com');

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId(['name' => 'Администратор', 'slug' => 'admin']));

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'role_id' => $roleId,
            'created_at' => $now,
        ]);

        return User::query()->findOrFail($user->id);
    }

    // ── 1. настройка: доступ и валидация ──

    public function test_settings_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/loyalty/settings')->assertStatus(401);
        $this->putJson('/api/v1/admin/loyalty/settings', ['enabled' => true, 'hours' => 24, 'min_level_slug' => 'friend'])->assertStatus(401);
    }

    public function test_a_plain_buyer_cannot_read_or_write_settings(): void
    {
        $buyer = $this->user('buyer@example.com');

        $this->getJson('/api/v1/admin/loyalty/settings', $this->headers($this->tokenFor($buyer)))
            ->assertStatus(403);

        $this->putJson('/api/v1/admin/loyalty/settings', [
            'enabled' => true,
            'hours' => 24,
            'min_level_slug' => 'friend',
        ], $this->headers($this->tokenFor($buyer)))->assertStatus(403);
    }

    public function test_staff_reads_defaults_and_updates_settings(): void
    {
        $staff = $this->staffUser();
        $headers = $this->headers($this->tokenFor($staff));

        $defaults = $this->getJson('/api/v1/admin/loyalty/settings', $headers)
            ->assertOk()
            ->json('data');

        self::assertFalse($defaults['enabled']);
        self::assertSame(24, $defaults['hours']);
        self::assertSame('friend', $defaults['min_level_slug']);
        // Список уровней приходит с сервера — выпадающий список порога не
        // зашивает фронтенд.
        self::assertSame(['start', 'friend', 'vip'], array_column($defaults['levels'], 'slug'));

        $this->putJson('/api/v1/admin/loyalty/settings', [
            'enabled' => true,
            'hours' => 500,
            'min_level_slug' => 'vip',
        ], $headers)->assertOk();

        $updated = $this->getJson('/api/v1/admin/loyalty/settings', $headers)
            ->assertOk()
            ->json('data');

        self::assertTrue($updated['enabled']);
        self::assertSame(500, $updated['hours']);
        self::assertSame('vip', $updated['min_level_slug']);
    }

    public function test_update_rejects_an_unknown_level_and_a_nonsense_window(): void
    {
        $staff = $this->staffUser();
        $headers = $this->headers($this->tokenFor($staff));

        // Порог существует в `loyalty_levels`, но не среди известных — список
        // ведёт сид, и произвольная строка открыла бы продажи «на уровне,
        // которого нет».
        $this->putJson('/api/v1/admin/loyalty/settings', [
            'enabled' => true,
            'hours' => 24,
            'min_level_slug' => 'platinum',
        ], $headers)->assertStatus(422);

        $this->putJson('/api/v1/admin/loyalty/settings', [
            'enabled' => true,
            'hours' => 0,
            'min_level_slug' => 'friend',
        ], $headers)->assertStatus(422);

        $this->putJson('/api/v1/admin/loyalty/settings', [
            'enabled' => true,
            'hours' => 100_000,
            'min_level_slug' => 'friend',
        ], $headers)->assertStatus(422);
    }

    /**
     * Кламп часов работает и в обход HTTP-валидации.
     *
     * HTTP-граница отклоняет `hours=0` и `hours=100_000` (422) — см. тест выше.
     * Но `save()` зовут и напрямую: сид, миграция, команда. Там валидации нет,
     * поэтому окно обязан привести сам сервис (`clampHours`): [1, MAX_HOURS].
     * Без этого клампа «0 часов» записался бы как 0, а «100000» — как есть.
     */
    public function test_save_clamps_the_window_even_without_http_validation(): void
    {
        $service = app(LoyaltySettingsService::class);

        $service->save(enabled: true, hours: 0, minLevelSlug: 'friend');
        self::assertSame('1', $this->storedHours(), 'save() обязан записать клампнутое 1, а не сырое 0');

        $service->save(enabled: true, hours: 100_000, minLevelSlug: 'friend');
        self::assertSame(
            (string) LoyaltySettingsService::MAX_HOURS,
            $this->storedHours(),
            'save() обязан записать клампнутый MAX_HOURS, а не 100000',
        );

        // И читатель тоже отдаёт клампнутое значение.
        self::assertSame(LoyaltySettingsService::MAX_HOURS, $service->forAdmin()['hours']);
    }

    /**
     * Сырое `early_access_hours` в `settings` — НЕ через `forAdmin()`.
     *
     * Нужно именно сырое значение: `forAdmin()` клампует и на чтении, поэтому
     * проверка через него не отличила бы «save() записал кламп» от «save()
     * записал сырое, а прочитали клампнутым». Сторож должен падать, если кламп
     * убрать из `save()`.
     */
    private function storedHours(): ?string
    {
        $raw = DB::table('settings')
            ->where('scope', LoyaltySettingsService::SCOPE)
            ->where('setting_key', LoyaltySettingsService::KEY_HOURS)
            ->value('value_json');

        return $raw === null ? null : (string) $raw;
    }

    // ── 2-3. гейт продаж ──

    public function test_a_guest_cannot_open_a_cart_before_the_public_start(): void
    {
        $this->earlyAccessSettings(enabled: true, hours: 48);
        $sessionId = $this->sessionOpeningTomorrow();

        $this->postJson('/api/v1/carts', ['session_id' => $sessionId])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SALES_CLOSED');
    }

    public function test_a_club_member_opens_a_cart_inside_the_early_access_window(): void
    {
        Bus::fake();

        $this->earlyAccessSettings(enabled: true, hours: 48);

        $sessionId = $this->sessionOpeningTomorrow();

        $member = $this->user('member@example.com');
        $this->paidOrderFor($member->id);
        $this->paidOrderFor($member->id);
        $this->paidOrderFor($member->id);

        $response = $this->postJson('/api/v1/carts', ['session_id' => $sessionId], $this->headers($this->tokenFor($member)))
            ->assertStatus(201);

        self::assertNotNull($response->json('data.id'));
    }

    public function test_a_member_below_the_threshold_is_still_blocked(): void
    {
        Bus::fake();

        $this->earlyAccessSettings(enabled: true, hours: 48, minLevelSlug: 'vip');

        $sessionId = $this->sessionOpeningTomorrow();

        // Три оплаченных — это «друг», а порог «vip» (10): ранний доступ не
        // открывается, покупатель ждёт общего старта, как и все.
        $friend = $this->user('friend@example.com');
        $this->paidOrderFor($friend->id);
        $this->paidOrderFor($friend->id);
        $this->paidOrderFor($friend->id);

        $this->postJson('/api/v1/carts', ['session_id' => $sessionId], $this->headers($this->tokenFor($friend)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SALES_CLOSED');
    }

    public function test_a_member_is_blocked_while_early_access_is_switched_off(): void
    {
        Bus::fake();

        $this->earlyAccessSettings(enabled: false, hours: 48);

        $sessionId = $this->sessionOpeningTomorrow();

        $member = $this->user('member@example.com');
        $this->paidOrderFor($member->id);
        $this->paidOrderFor($member->id);
        $this->paidOrderFor($member->id);

        $this->postJson('/api/v1/carts', ['session_id' => $sessionId], $this->headers($this->tokenFor($member)))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SALES_CLOSED');
    }

    public function test_a_member_can_add_a_seat_during_early_access_and_a_guest_cannot(): void
    {
        Bus::fake();

        $this->earlyAccessSettings(enabled: true, hours: 48);

        [, , $sessionId, $inventoryId] = $this->seedSellableSeat(available: 2);

        DB::table('sessions')->where('id', $sessionId)->update([
            'sales_start_at' => now()->addDay()->toDateTimeString(),
            'sales_end_at' => now()->addWeeks(2)->toDateTimeString(),
        ]);

        $member = $this->user('member@example.com');
        $this->paidOrderFor($member->id);
        $this->paidOrderFor($member->id);
        $this->paidOrderFor($member->id);

        // Гость: окно ещё не открылось — место не бронируется.
        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryId,
            'quantity' => 1,
        ], ['X-Cart-Token' => 'guest-' . Str::uuid()->toString()])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SALES_CLOSED');

        // Клубный покупатель: бронирует место внутри early-окна.
        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryId,
            'quantity' => 1,
        ], $this->headers($this->tokenFor($member)))
            ->assertStatus(201);
    }
}
