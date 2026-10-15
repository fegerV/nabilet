<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Auth\Services\SessionIssuer;
use Nabilet\Modules\Loyalty\Domain\BonusReason;
use Nabilet\Modules\Loyalty\Jobs\AccrueOrderBonusJob;
use Nabilet\Modules\Loyalty\Services\BonusService;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * «Клуб зрителей», фаза 2a — `/api/v1/me/bonus` и `/api/v1/me/bonus/transactions`.
 *
 * ЧТО ЗДЕСЬ ЗАЩИЩАЕТСЯ, И ПОЧЕМУ ИМЕННО ЭТИ ТЕСТЫ
 *
 *   1. ДОСТУП. Оба пути под `auth:api`: баланс и история — это личные данные, и
 *      гостю нечего показывать. Первый тест стучится в оба пути без токена.
 *   2. ВЛАДЕЛЕЦ. Ни один метод не принимает идентификатор пользователя —
 *      владелец приходит из Bearer-токена. Тест
 *      `two_buyers_do_not_see_each_other` проверяет, что баланс и журнал делятся
 *      ровно по токену, а не расширяются подстановкой.
 *   3. ФОРМА ОТВЕТА. `data` — СПИСОК, а не объект пагинатора: иначе клиент,
 *      читающий `data[0].delta`, получает `undefined` (тот же дефект, что был у
 *      списка заказов). Внутренние BIGINT наружу не уходят: `id` и `order_id` —
 *      `public_id` (26 символов), `user_id` в ответе отсутствует.
 *   4. ЕДИНИЦЫ. `balance` — минорные единицы, `bonuses` — тот же баланс в
 *      «бонусах» витрины по курсу 100. Тест проверяет оба числа на одном ответе:
 *      если курс уедет в клиента или разойдётся с `BonusPolicy`, это видно.
 *   5. ПУСТОЙ СЧЁТ — ЭТО НОЛЬ. Счёт заводится лениво, поэтому покупатель без
 *      заказов обязан получить «0 бонусов», а не 404.
 */
class BonusApiTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private function seedUser(string $email = 'buyer@example.com'): int
    {
        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => $email,
            'status' => 'active',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Токен выдаётся напрямую, а не через `/auth/register` + `/auth/login`:
     * тестам нужен контроль над `user_id` (заказ оформляется на того же
     * покупателя), а форма входа проверяется своими тестами и здесь была бы
     * лишним звеном, падение которого выглядело бы падением бонусного API.
     */
    private function tokenFor(int $userId): string
    {
        return $this->app->make(SessionIssuer::class)->issue($userId);
    }

    private function headers(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    private function paidOrderFor(?int $userId, int $priceAmount = 5_000_000): Order
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(
            available: 1,
            priceAmount: $priceAmount,
        );

        $service = app(OrderService::class);

        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'customer_email' => 'buyer@example.test',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $service->markAwaitingPayment($order);

        return $service->markPaid($order->fresh());
    }

    private function accrue(Order $order): void
    {
        (new AccrueOrderBonusJob((int) $order->id))->handle(app(BonusService::class));
    }

    // ── 1. доступ ──────────────────────────────────────────────────────────

    public function test_both_endpoints_require_an_account(): void
    {
        $this->getJson('/api/v1/me/bonus')->assertStatus(401);
        $this->getJson('/api/v1/me/bonus/transactions')->assertStatus(401);
    }

    // ── 2. баланс ──────────────────────────────────────────────────────────

    public function test_a_buyer_without_orders_sees_a_zero_balance(): void
    {
        $userId = $this->seedUser();

        $data = $this->getJson('/api/v1/me/bonus', $this->headers($this->tokenFor($userId)))
            ->assertOk()
            ->json('data');

        // Счёт заводится лениво: «нет счёта» — это ноль бонусов, а не 404.
        $this->assertSame(0, $data['balance']);
        $this->assertSame(0, $data['bonuses']);
        $this->assertSame('RUB', $data['currency']);

        // Запрос баланса не должен заводить пустой счёт: иначе таблица
        // заполнится нулями от каждого открытия кабинета.
        $this->assertSame(0, DB::table('bonus_accounts')->count());
    }

    public function test_the_balance_reflects_the_accrual_in_both_units(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $token = $this->tokenFor($userId);

        $order = $this->paidOrderFor($userId, priceAmount: 5_000_000);
        $this->accrue($order);

        $data = $this->getJson('/api/v1/me/bonus', $this->headers($token))
            ->assertOk()
            ->json('data');

        // 5 000 000 × 5% = 250 000 минорных единиц = 2 500 бонусов.
        $this->assertSame(250_000, $data['balance']);
        $this->assertSame(2_500, $data['bonuses']);
        $this->assertSame('RUB', $data['currency']);

        // Оба числа — одно значение в двух единицах; разойтись они не могут,
        // потому что приходят из одного `Money` и одного курса.
        $this->assertSame(intdiv($data['balance'], 100), $data['bonuses']);
    }

    // ── 3. история ─────────────────────────────────────────────────────────

    public function test_the_journal_lists_the_accrual_with_the_order_public_id(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $token = $this->tokenFor($userId);

        $order = $this->paidOrderFor($userId, priceAmount: 5_000_000);
        $this->accrue($order);

        $data = $this->getJson('/api/v1/me/bonus/transactions', $this->headers($token))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data');

        $row = $data[0];

        $this->assertSame(250_000, $row['delta']);
        $this->assertSame(BonusReason::ACCRUAL, $row['reason']);

        // `order_id` — это `orders.public_id` (26 символов), а не внутренний
        // BIGINT: внутренний идентификатор не покидает API (`DATABASE.md` §9).
        $this->assertSame($order->public_id, $row['order_id']);
        $this->assertSame(26, mb_strlen($row['order_id']));

        // `id` строки журнала — тоже public_id, а не `bonus_transactions.id`.
        $this->assertIsString($row['id']);
        $this->assertSame(26, mb_strlen($row['id']));
        $this->assertNotNull($row['created_at']);

        // Внутренние идентификаторы в ответе отсутствуют.
        $this->assertArrayNotHasKey('user_id', $row);
    }

    public function test_the_journal_is_newest_first(): void
    {
        Bus::fake();

        $userId = $this->seedUser();
        $token = $this->tokenFor($userId);

        $first = $this->paidOrderFor($userId, priceAmount: 5_000_000);
        $this->accrue($first);

        $second = $this->paidOrderFor($userId, priceAmount: 10_000_000);
        $this->accrue($second);

        $data = $this->getJson('/api/v1/me/bonus/transactions', $this->headers($token))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->json('data');

        // Свежие сверху: первый элемент — начисление по второму заказу.
        $this->assertSame($second->public_id, $data[0]['order_id']);
        $this->assertSame(500_000, $data[0]['delta']);
        $this->assertSame($first->public_id, $data[1]['order_id']);
        $this->assertSame(250_000, $data[1]['delta']);
    }

    public function test_a_movement_without_an_order_reports_a_null_order_id(): void
    {
        $userId = $this->seedUser();
        $token = $this->tokenFor($userId);

        // Подарок за регистрацию: строка журнала без заказа. `order_id` — null,
        // а не отсутствующий ключ: клиент различает «нет заказа» и «поле не
        // пришло».
        DB::table('bonus_transactions')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'user_id' => $userId,
            'order_id' => null,
            'delta' => 10_000,
            'reason' => BonusReason::REGISTRATION,
            'dedupe_key' => null,
            'created_at' => now()->toDateTimeString(),
        ]);

        $data = $this->getJson('/api/v1/me/bonus/transactions', $this->headers($token))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data');

        $this->assertArrayHasKey('order_id', $data[0]);
        $this->assertNull($data[0]['order_id']);
        $this->assertSame(10_000, $data[0]['delta']);
        $this->assertSame(BonusReason::REGISTRATION, $data[0]['reason']);
    }

    public function test_the_journal_is_paginated(): void
    {
        $userId = $this->seedUser();
        $token = $this->tokenFor($userId);

        foreach ([1, 2, 3] as $index) {
            DB::table('bonus_transactions')->insert([
                'public_id' => (string) Str::ulid()->toBase32(),
                'user_id' => $userId,
                'order_id' => null,
                'delta' => $index,
                'reason' => BonusReason::ACCRUAL,
                'dedupe_key' => null,
                'created_at' => now()->addSeconds($index)->toDateTimeString(),
            ]);
        }

        $response = $this->getJson(
            '/api/v1/me/bonus/transactions?per_page=2',
            $this->headers($token),
        )->assertOk();

        // `data` — список, `meta` — пагинация. Не объект пагинатора: иначе
        // список оказался бы на два уровня глубже обещанного контрактом.
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(2, $response->json('meta.per_page'));
    }

    // ── 4. владелец ────────────────────────────────────────────────────────

    public function test_two_buyers_do_not_see_each_other(): void
    {
        Bus::fake();

        $first = $this->seedUser('buyer1@example.com');
        $second = $this->seedUser('buyer2@example.com');

        $firstToken = $this->tokenFor($first);
        $secondToken = $this->tokenFor($second);

        $order = $this->paidOrderFor($first, priceAmount: 5_000_000);
        $this->accrue($order);

        $this->assertSame(
            250_000,
            $this->getJson('/api/v1/me/bonus', $this->headers($firstToken))->json('data.balance'),
        );
        $this->assertSame(
            0,
            $this->getJson('/api/v1/me/bonus', $this->headers($secondToken))->json('data.balance'),
        );

        $this->getJson('/api/v1/me/bonus/transactions', $this->headers($firstToken))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/me/bonus/transactions', $this->headers($secondToken))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->getJson('/api/v1/me/bonus', ['Authorization' => 'Bearer not-a-real-token'])
            ->assertStatus(401);
    }

    public function test_the_journal_reports_the_reason_vocabulary(): void
    {
        $userId = $this->seedUser();
        $token = $this->tokenFor($userId);

        foreach (BonusReason::all() as $reason) {
            DB::table('bonus_transactions')->insert([
                'public_id' => (string) Str::ulid()->toBase32(),
                'user_id' => $userId,
                'order_id' => null,
                'delta' => 1,
                'reason' => $reason,
                'dedupe_key' => null,
                'created_at' => now()->toDateTimeString(),
            ]);
        }

        $rows = $this->getJson('/api/v1/me/bonus/transactions?per_page=100', $this->headers($token))
            ->assertOk()
            ->json('data');

        $reasons = array_column($rows, 'reason');
        sort($reasons);
        $expected = BonusReason::all();
        sort($expected);

        // Словарь отдаётся как есть: клиент сопоставляет `reason` с текстом
        // («кэшбэк», «возврат», «сгорели»), и неизвестный код он показать не
        // сможет. Тест держит соответствие `BonusReason` и ответа API.
        $this->assertSame($expected, $reasons);
    }
}
