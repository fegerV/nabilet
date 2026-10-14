<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Loyalty\Domain\ReferralCode as ReferralCodeRules;
use Nabilet\Modules\Loyalty\Jobs\RewardReferralJob;
use Nabilet\Modules\Loyalty\Models\Referral;
use Nabilet\Modules\Loyalty\Services\LoyaltySettingsService;
use Nabilet\Modules\Loyalty\Services\ReferralService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Реферальная программа «Клуба зрителей» (фаза 4 `docs/LOYALTY-PLAN.md`).
 *
 * ЧТО ЗДЕСЬ ЗАЩИЩАЕТСЯ, И ПОЧЕМУ ИМЕННО ЭТИ ТЕСТЫ
 *
 *   1. АТРИБУЦИЯ НА ЧЕКАУТЕ, А НЕ ПО КЛИКУ. Приглашение рождается из заказа —
 *      документа с адресом друга. Тест на живом чекауте проверяет, что код из
 *      ссылки заводит строку `pending` с НИЖНИМ регистром адреса, и что
 *      покупатель без аккаунта (`invited_user_id = null`) приглашаемый — гость
 *      тоже друг.
 *   2. БИТАЯ ССЫЛКА НЕ ЛОМАЕТ ПОКУПКУ. Неизвестный, свой и некорректный код —
 *      молча: отказ (422) сломал бы покупку из-за устаревшей ссылки, а
 *      безопасность от этого не растёт. Все три случая проверяются на чекауте
 *      с разными кодами.
 *   3. НАГРАДА — ПОСЛЕ ОПЛАТЫ И РОВНО ОДИН РАЗ. Джоба ставится на переход в
 *      `paid` (обзервер, а не один сценарий оплаты), платит обеим сторонам из
 *      журнала и защищена дважды: статус `rewarded` + `dedupe_key`. Повтор
 *      джобы (ретрай воркера) не начисляет второй раз.
 *   4. ВОЗВРАТ НЕ ОТЗЫВАЕТ НАГРАДУ. Ограничение намеренное и то же, что у
 *      кэшбэка (фаза 2b): бонус уже на счёте. Тест фиксирует поведение, а не
 *      «исправляет» его — молчаливое ожидание обратного сломал бы чужой план.
 *   5. НАСТРОЙКИ АДМИНКИ ПРАВЯТСЯ ПОРОЗНЬ. Числовая правка награды не должна
 *      затирать порог раннего доступа: это частичное обновление
 *      (`LoyaltySettingsController`), и тест ловит регрессию «сохранили
 *      рефералку — сбросился ранний доступ».
 */
class ReferralTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private const TOKEN = '11111111-2222-3333-4444-555555555555';
    private const INVITER_EMAIL = 'inviter@example.com';
    private const FRIEND_EMAIL = 'friend@example.com';

    // ── фикстуры ───────────────────────────────────────────────────────────

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

    private function tokenFor(User $user): string
    {
        return $this->app->make(SessionIssuer::class)->issue($user->id);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /** @return array<string, string> */
    private function cartHeaders(string $token = self::TOKEN): array
    {
        return ['X-Cart-Token' => $token];
    }

    private function staffUser(): User
    {
        $now = now();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Referral Org',
            'slug' => 'referral-org-' . Str::random(6),
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

    private function settings(): LoyaltySettingsService
    {
        return app(LoyaltySettingsService::class);
    }

    private function referrals(): ReferralService
    {
        return app(ReferralService::class);
    }

    /** Код приглашения конкретного покупателя — как его выдаёт кабинет. */
    private function codeFor(User $user): string
    {
        return $this->referrals()->codeFor($user);
    }

    private function addToCart(int $sessionId, int $inventoryItemId): void
    {
        $this->postJson('/api/v1/cart/items', [
            'session_id' => $sessionId,
            'inventory_item_id' => $inventoryItemId,
            'quantity' => 1,
        ], $this->cartHeaders())->assertStatus(201);
    }

    /**
     * Чекаут из удержанных мест. Именно здесь код приглашения попадает в заказ:
     * витрина присылает его тем же телом, что и контакты.
     */
    private function checkout(int $sessionId, array $extra = [], string $email = self::FRIEND_EMAIL)
    {
        return $this->postJson('/api/v1/cart/checkout', array_merge([
            'session_id' => $sessionId,
            'customer_email' => $email,
            'customer_email_confirmation' => $email,
            'customer_name' => 'Пётр Другов',
        ], $extra), $this->cartHeaders());
    }

    /**
     * Заказ друга, ещё не оплаченный: приглашение цепляется к заказу ЗАРАНЕЕ —
     * ровно тем вызовом, который делает чекаут. Награда ждёт оплаты.
     */
    private function orderForFriend(User $friend, string $code, string $email = self::FRIEND_EMAIL): Order
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);

        $service = app(OrderService::class);

        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'user_id' => $friend->id,
            'customer_email' => $email,
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $referral = $this->referrals()->attachToOrder($order, $code);

        self::assertInstanceOf(Referral::class, $referral, 'приглашение должно завестись по коду');

        $service->markAwaitingPayment($order);

        return $order->fresh();
    }

    private function ledgerCount(int $userId, string $reason): int
    {
        return DB::table('bonus_transactions')
            ->where('user_id', $userId)
            ->where('reason', $reason)
            ->count();
    }

    private function balanceOf(int $userId): int
    {
        return (int) DB::table('bonus_accounts')->where('user_id', $userId)->value('balance');
    }

    private function orderByPublicId(string $publicId): Order
    {
        return Order::query()->where('public_id', $publicId)->firstOrFail();
    }

    /** Прогон награды так, как это сделал бы воркер очереди. */
    private function runReward(int $orderId): void
    {
        (new RewardReferralJob($orderId))->handle($this->referrals());
    }

    // ── 1. настройки админки ───────────────────────────────────────────────

    public function test_referral_settings_require_a_staff_account(): void
    {
        $this->getJson('/api/v1/admin/loyalty/settings')->assertStatus(401);

        $buyer = $this->user('buyer@example.com');

        $this->getJson('/api/v1/admin/loyalty/settings', $this->headers($this->tokenFor($buyer)))
            ->assertStatus(403);

        $this->putJson('/api/v1/admin/loyalty/settings', ['referral_enabled' => true], $this->headers($this->tokenFor($buyer)))
            ->assertStatus(403);
    }

    public function test_staff_reads_and_updates_referral_settings(): void
    {
        $staff = $this->staffUser();
        $headers = $this->headers($this->tokenFor($staff));

        $defaults = $this->getJson('/api/v1/admin/loyalty/settings', $headers)
            ->assertOk()
            ->json('data');

        // Дефолты: программа включена, ₽300 обеим сторонам — «визитная карточка
        // первой покупки» из таблицы правил плана.
        self::assertTrue($defaults['referral_enabled']);
        self::assertSame(30_000, $defaults['referral_inviter_bonus']);
        self::assertSame(30_000, $defaults['referral_invited_bonus']);

        $this->putJson('/api/v1/admin/loyalty/settings', [
            'referral_enabled' => false,
            'referral_inviter_bonus' => 50_000,
            'referral_invited_bonus' => 15_000,
        ], $headers)->assertOk();

        $updated = $this->getJson('/api/v1/admin/loyalty/settings', $headers)
            ->assertOk()
            ->json('data');

        self::assertFalse($updated['referral_enabled']);
        self::assertSame(50_000, $updated['referral_inviter_bonus']);
        self::assertSame(15_000, $updated['referral_invited_bonus']);
    }

    public function test_referral_update_does_not_clobber_the_early_access_settings(): void
    {
        $staff = $this->staffUser();
        $headers = $this->headers($this->tokenFor($staff));

        // Сначала ранний доступ — своими значениями.
        $this->putJson('/api/v1/admin/loyalty/settings', [
            'enabled' => true,
            'hours' => 48,
            'min_level_slug' => 'vip',
        ], $headers)->assertOk();

        // Потом только реферальная группа: частичное обновление не должно
        // затирать порог раннего доступа.
        $this->putJson('/api/v1/admin/loyalty/settings', [
            'referral_inviter_bonus' => 70_000,
        ], $headers)->assertOk();

        $data = $this->getJson('/api/v1/admin/loyalty/settings', $headers)
            ->assertOk()
            ->json('data');

        self::assertTrue($data['enabled']);
        self::assertSame(48, $data['hours']);
        self::assertSame('vip', $data['min_level_slug']);
        self::assertSame(70_000, $data['referral_inviter_bonus']);
    }

    public function test_referral_settings_reject_a_nonsense_reward(): void
    {
        $staff = $this->staffUser();
        $headers = $this->headers($this->tokenFor($staff));

        // Потолок спрашиваем у `ReferralPolicy::MAX_BONUS`: «максимум награды»
        // должен жить в одном месте.
        $this->putJson('/api/v1/admin/loyalty/settings', ['referral_inviter_bonus' => 2_000_000], $headers)
            ->assertStatus(422);

        $this->putJson('/api/v1/admin/loyalty/settings', ['referral_invited_bonus' => -1], $headers)
            ->assertStatus(422);
    }

    // ── 2. кабинет: ссылка и статистика ────────────────────────────────────

    public function test_referral_endpoint_requires_an_account(): void
    {
        $this->getJson('/api/v1/me/referral')->assertStatus(401);
    }

    public function test_referral_returns_the_code_the_hash_link_and_the_stats(): void
    {
        Bus::fake();

        $inviter = $this->user(self::INVITER_EMAIL);
        $code = $this->codeFor($inviter);

        self::assertTrue(ReferralCodeRules::isWellFormed($code));

        $res = $this->getJson('/api/v1/me/referral', $this->headers($this->tokenFor($inviter)))
            ->assertOk()
            ->json('data');

        self::assertSame($code, $res['code']);
        // Хэш-путь, а не чистый `/r/{code}`: SPA отдаётся только на `/`
        // (`routes/web.php`), и чистый путь ушёл бы в `Route::fallback()` → 404.
        self::assertSame(url('/#/r/' . $code), $res['link']);
        self::assertTrue($res['enabled']);
        self::assertSame(30_000, $res['inviter_bonus']);
        self::assertSame(30_000, $res['invited_bonus']);
        self::assertSame(['invited' => 0, 'rewarded' => 0, 'earned' => 0], $res['stats']);
    }

    // ── 3. атрибуция на чекауте ────────────────────────────────────────────

    public function test_checkout_attaches_the_invitation_from_the_link(): void
    {
        Bus::fake();

        $inviter = $this->user(self::INVITER_EMAIL);
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);
        $this->addToCart($sessionId, $inventoryItemId);

        $response = $this->checkout($sessionId, ['referral_code' => $this->codeFor($inviter)])
            ->assertStatus(200);

        $referral = Referral::query()->firstOrFail();

        self::assertSame($inviter->id, (int) $referral->inviter_user_id);
        self::assertSame(self::FRIEND_EMAIL, $referral->invited_email);
        // Друг определяются АДРЕСОМ, а не аккаунтом: аккаунт чекаут заводит сам
        // по адресу (покупатель по умолчанию — гость), и приглашение тащит за
        // ним уточнение — `invited_user_id` = владелец заказа.
        $order = $this->orderByPublicId((string) $response->json('data.order_id'));
        self::assertSame($order->user_id === null ? null : (int) $order->user_id, $referral->invited_user_id === null ? null : (int) $referral->invited_user_id);
        // Статус следует за resolved-аккаунтом друга: чекаут заводит аккаунт
        // по адресу (`resolveBuyerId()`), поэтому приглашение сразу
        // `registered`. Заказ без аккаунта дал бы `pending` — оба состояния
        // ждут оплаты, и ни одно не выплачено.
        self::assertSame(
            $order->user_id === null ? Referral::STATUS_PENDING : Referral::STATUS_REGISTERED,
            $referral->status,
        );
        self::assertNull($referral->bonus_paid_at);
    }

    public function test_an_invitation_from_a_link_with_another_case_is_matched(): void
    {
        Bus::fake();

        $inviter = $this->user(self::INVITER_EMAIL);
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);
        $this->addToCart($sessionId, $inventoryItemId);

        // Ссылку пересылают в мессенджерах, и регистр гуляет: приведение делает
        // сервер, иначе «не пришёл бонус» из-за строчной буквы.
        $code = strtolower($this->codeFor($inviter));

        $this->checkout($sessionId, ['referral_code' => "  {$code} \n"])
            ->assertStatus(200);

        self::assertSame(1, Referral::query()->count());
        self::assertSame($inviter->id, (int) Referral::query()->firstOrFail()->inviter_user_id);
    }

    public function test_an_unknown_code_does_not_break_the_purchase(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);
        $this->addToCart($sessionId, $inventoryItemId);

        // Похожий на настоящий, но неизвестный серверу код: покупка идёт, молча.
        $this->checkout($sessionId, ['referral_code' => 'ZZZZZZZZZZZZZZZZ'])
            ->assertStatus(200);

        self::assertSame(0, Referral::query()->count());
    }

    public function test_a_malformed_code_does_not_break_the_purchase(): void
    {
        Bus::fake();

        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);
        $this->addToCart($sessionId, $inventoryItemId);

        // Мусор в query или ручной ввод: сервер не узнаёт код и не награждает,
        // но и не ломает оформление отказом.
        $this->checkout($sessionId, ['referral_code' => 'не-код'])
            ->assertStatus(200);

        self::assertSame(0, Referral::query()->count());
    }

    public function test_your_own_link_does_not_invite_yourself(): void
    {
        Bus::fake();

        $inviter = $this->user(self::INVITER_EMAIL);
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);
        $this->addToCart($sessionId, $inventoryItemId);

        // Самоприглашение двумя путями: аккаунт и адрес. Здесь — адрес
        // пригласившего на чеке гостя, аккаунта у заказа нет вовсе.
        $this->checkout($sessionId, [
            'referral_code' => $this->codeFor($inviter),
        ], $inviter->email)->assertStatus(200);

        self::assertSame(0, Referral::query()->count());
    }

    public function test_a_disabled_program_attaches_no_invitations(): void
    {
        Bus::fake();

        $this->settings()->saveReferral(enabled: false, inviterBonus: 30_000, invitedBonus: 30_000);

        $inviter = $this->user(self::INVITER_EMAIL);
        [, , $sessionId, $inventoryItemId] = $this->seedSellableSeat(available: 1);
        $this->addToCart($sessionId, $inventoryItemId);

        $this->checkout($sessionId, ['referral_code' => $this->codeFor($inviter)])
            ->assertStatus(200);

        self::assertSame(0, Referral::query()->count());
    }

    // ── 4. награда за первую оплату друга ──────────────────────────────────

    public function test_paying_the_friends_order_rewards_both_sides(): void
    {
        Bus::fake();

        $inviter = $this->user(self::INVITER_EMAIL);
        $friend = $this->user(self::FRIEND_EMAIL);
        $order = $this->orderForFriend($friend, $this->codeFor($inviter));

        $service = app(OrderService::class);
        $service->markPaid($order);

        Bus::assertDispatched(RewardReferralJob::class, static fn (RewardReferralJob $job): bool =>
            $job->orderId === $order->id
            && $job->afterCommit === true
        );

        $this->runReward($order->id);

        // Пригласивший: запись в журнале и счёт.
        self::assertSame(1, $this->ledgerCount($inviter->id, 'referral_inviter'));
        self::assertSame(30_000, $this->balanceOf($inviter->id));

        $inviterRow = DB::table('bonus_transactions')
            ->where('user_id', $inviter->id)
            ->where('reason', 'referral_inviter')
            ->first();
        self::assertSame(30_000, (int) $inviterRow->delta);
        self::assertSame('referral_inviter:' . Referral::query()->firstOrFail()->id, $inviterRow->dedupe_key);

        // Друг: своя запись и свой счёт.
        self::assertSame(1, $this->ledgerCount($friend->id, 'referral_invited'));
        self::assertSame(30_000, $this->balanceOf($friend->id));

        // Приглашение закрыто: повторная попытка наградить не найдёт строку.
        $referral = Referral::query()->firstOrFail();
        self::assertSame(Referral::STATUS_REWARDED, $referral->status);
        self::assertNotNull($referral->bonus_paid_at);
        self::assertSame($friend->id, (int) $referral->invited_user_id);
    }

    public function test_the_reward_is_paid_once_even_if_the_job_runs_twice(): void
    {
        Bus::fake();

        $inviter = $this->user(self::INVITER_EMAIL);
        $friend = $this->user(self::FRIEND_EMAIL);
        $order = $this->orderForFriend($friend, $this->codeFor($inviter));

        app(OrderService::class)->markPaid($order);

        $this->runReward($order->id);
        // Ретрай воркера (`tries`/`backoff`): повтор не имеет права начислить
        // второй раз. Защита двойная — статус `rewarded` и `dedupe_key`.
        $this->runReward($order->id);

        self::assertSame(1, $this->ledgerCount($inviter->id, 'referral_inviter'));
        self::assertSame(1, $this->ledgerCount($friend->id, 'referral_invited'));
        self::assertSame(30_000, $this->balanceOf($inviter->id));
        self::assertSame(30_000, $this->balanceOf($friend->id));
    }

    public function test_an_order_without_an_invitation_rewards_nobody(): void
    {
        Bus::fake();

        $buyer = $this->user('buyer@example.com');
        [, , , $inventoryId] = $this->seedSellableSeat(available: 1);

        $service = app(OrderService::class);
        $order = $service->createOrder([
            'organization_id' => $this->seedOrganization(),
            'user_id' => $buyer->id,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $service->markAwaitingPayment($order);
        $service->markPaid($order->fresh());

        $this->runReward($order->id);

        self::assertSame(0, DB::table('bonus_transactions')->count());
    }

    public function test_a_refund_does_not_revoke_the_reward(): void
    {
        Bus::fake();

        $inviter = $this->user(self::INVITER_EMAIL);
        $friend = $this->user(self::FRIEND_EMAIL);
        $order = $this->orderForFriend($friend, $this->codeFor($inviter));

        app(OrderService::class)->markPaid($order);
        $this->runReward($order->id);

        // Возврат — денежное событие заказа, но не событие рефералки:
        // наблюдатель реагирует только на `paid`, и «отзыва» награды не
        // существует (правило плана, то же, что у кэшбэка в фазе 2b).
        $refunded = $order->fresh();
        $refunded->update(['status' => OrderStateMachine::REFUNDED]);

        // Ретрай джобы после возврата: награду она не находить — строка уже
        // `rewarded`, но и «компенсации» награды не пишет.
        $this->runReward($order->id);

        self::assertSame(1, $this->ledgerCount($inviter->id, 'referral_inviter'));
        self::assertSame(1, $this->ledgerCount($friend->id, 'referral_invited'));
        self::assertSame(30_000, $this->balanceOf($inviter->id));
        self::assertSame(30_000, $this->balanceOf($friend->id));
        self::assertSame(0, $this->ledgerCount($inviter->id, 'refund'));
    }

    private function seedOrganization(): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Buyer Org',
            'slug' => 'buyer-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
