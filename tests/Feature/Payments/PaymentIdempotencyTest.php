<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Идемпотентность инициации платежа (аудит S-07).
 *
 * ДЕФЕКТ. `POST /api/v1/payments` принимал `idempotency_key` как `nullable` и
 * передавал в сервис `null`, когда его не было. Сервис в этом случае подставлял
 * `Str::ulid()` — то есть НОВЫЙ ключ на каждый вызов. Единственной защитой
 * оставалось правило «один активный платёж на заказ», а оно не покрывает случай,
 * когда первый платёж уже завершился (`succeeded` / `canceled`): тогда повтор
 * создавал вторую строку. Двойной клик по кнопке «Оплатить» и ретрай на плохой
 * мобильной сети — это норма, а не атака, поэтому дефект срабатывал сам собой:
 * сверка с провайдером видела два зачисления против одной оплаты, а возврат мог
 * уйти дважды.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Требование ключа, ответ 200 на повтор вместо 201, отсутствие
 * второй строки в БД (в том числе когда платёж уже завершён — именно эта ветка
 * раньше падала на UNIQUE `uq_payments_idempotency` пятисоткой), и разделение
 * «тот же ключ для другого заказа» (409) от «тот же ключ для того же заказа» (200).
 *
 * ПРОВАЙДЕР — ДЕМО. `nabilet.payment.demo_mode = true` заставляет
 * `YooKassaProvider::createPayment()` вернуть локальную ссылку подтверждения
 * вместо вызова API ЮKassa: тест не зависит ни от сети, ни от ключей.
 */
class PaymentIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private OrderService $orders;
    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nabilet.payment.demo_mode' => true]);

        $this->orders = $this->app->make(OrderService::class);
        $this->payments = $this->app->make(PaymentService::class);
    }

    /**
     * Без ключа платёж не создаётся вовсе.
     *
     * Это и есть суть S-07: «ключа нет» должно означать отказ, а не «сгенерируем
     * свой» — иначе повторный запрос неотличим от нового и дедупликация
     * невозможна в принципе.
     */
    public function test_a_payment_cannot_be_started_without_an_idempotency_key(): void
    {
        $order = $this->pendingOrder();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/payments', ['order_id' => $order->public_id]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertNotNull(
            $response->json('error.details.fields.idempotency_key'),
            'ответ обязан называть поле, которого не хватает'
        );

        $this->assertSame(0, DB::table('payments')->count(), 'платёж без ключа не должен создаваться');
    }

    /** Пустая строка — тоже «нет ключа»: клиент не может отделаться заглушкой. */
    public function test_an_empty_key_is_refused(): void
    {
        $order = $this->pendingOrder();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/payments', [
            'order_id' => $order->public_id,
            'idempotency_key' => '   ',
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('payments')->count());
    }

    /** Контракт объявляет ключ заголовком `Idempotency-Key` — он должен работать. */
    public function test_the_key_may_come_from_the_contract_header(): void
    {
        $order = $this->pendingOrder();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/payments', ['order_id' => $order->public_id], [
            'Idempotency-Key' => 'pay_header_key_1',
        ]);

        $response->assertStatus(201);
        $this->assertSame(
            'pay_header_key_1',
            DB::table('payments')->where('id', $response->json('data.payment.id'))->value('idempotency_key')
        );
    }

    /**
     * Повтор с тем же ключом возвращает ТОТ ЖЕ платёж и код 200.
     *
     * 201 на повторе был бы ложью: клиент не может отличить «я создал платёж» от
     * «я повторил запрос», а именно это различие ему и нужно, чтобы не уйти
     * оплачивать второй раз.
     */
    public function test_a_replay_with_the_same_key_returns_the_same_payment_and_200(): void
    {
        $order = $this->pendingOrder();
        $this->actingAsAdmin();

        $first = $this->postJson('/api/v1/payments', [
            'order_id' => $order->public_id,
            'idempotency_key' => 'pay_double_click_1',
        ]);

        $second = $this->postJson('/api/v1/payments', [
            'order_id' => $order->public_id,
            'idempotency_key' => 'pay_double_click_1',
        ]);

        $first->assertStatus(201);
        $second->assertStatus(200);

        $this->assertSame($first->json('data.payment.id'), $second->json('data.payment.id'));
        $this->assertSame($first->json('data.confirmation_url'), $second->json('data.confirmation_url'));
        $this->assertSame(1, DB::table('payments')->count(), 'повтор создал второй платёж');
    }

    /**
     * Повтор после ЗАВЕРШЕНИЯ платежа — 200, а не 500.
     *
     * Это самая дорогая ветка дефекта: завершённый платёж не попадает под
     * «один активный платёж на заказ», поэтому код уходил создавать вторую
     * строку с тем же ключом и падал на UNIQUE `uq_payments_idempotency`.
     * Покупатель получал 500 на повторном нажатии «Оплатить» по уже оплаченному
     * заказу, а не внятный ответ «оплата уже прошла».
     */
    public function test_a_replay_after_the_payment_is_terminal_returns_it_instead_of_failing(): void
    {
        $order = $this->pendingOrder();
        $this->actingAsAdmin();

        $first = $this->postJson('/api/v1/payments', [
            'order_id' => $order->public_id,
            'idempotency_key' => 'pay_already_paid_1',
        ])->assertStatus(201);

        $paymentId = (int) $first->json('data.payment.id');

        DB::table('payments')->where('id', $paymentId)->update([
            'status' => PaymentStateMachine::SUCCEEDED,
            'paid_at' => now(),
        ]);

        $replay = $this->postJson('/api/v1/payments', [
            'order_id' => $order->public_id,
            'idempotency_key' => 'pay_already_paid_1',
        ]);

        $replay->assertStatus(200);
        $this->assertSame($paymentId, (int) $replay->json('data.payment.id'));
        $this->assertSame(1, DB::table('payments')->count());
    }

    /**
     * Тот же ключ для ДРУГОГО заказа — 409, а не чужой платёж.
     *
     * Молчаливая выдача платежа первого заказа отправила бы покупателя платить
     * по чужой ссылке с чужой суммой.
     */
    public function test_the_same_key_for_another_order_is_a_conflict(): void
    {
        // Порядок важен: администратор привязывается к организации первого
        // заказа, поэтому заказ создаётся раньше, чем выдаётся роль.
        $first = $this->pendingOrder();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/payments', [
            'order_id' => $first->public_id,
            'idempotency_key' => 'pay_shared_key_1',
        ])->assertStatus(201);

        $second = $this->pendingOrder();

        $response = $this->postJson('/api/v1/payments', [
            'order_id' => $second->public_id,
            'idempotency_key' => 'pay_shared_key_1',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(
            0,
            DB::table('payments')->where('order_id', $second->id)->count(),
            'второй заказ не должен получить платёж по чужому ключу'
        );
    }

    /**
     * Ретрай с ДРУГИМ ключом не создаёт второй активный платёж.
     *
     * Правило «один активный платёж на заказ» остаётся вторым слоем: даже если
     * клиент по какой-то причине сгенерировал новый ключ, заказ не получит две
     * параллельные ссылки на оплату.
     */
    public function test_a_retry_with_a_different_key_reuses_the_active_payment(): void
    {
        $order = $this->pendingOrder();
        $this->actingAsAdmin();

        $first = $this->postJson('/api/v1/payments', [
            'order_id' => $order->public_id,
            'idempotency_key' => 'pay_attempt_a',
        ])->assertStatus(201);

        $second = $this->postJson('/api/v1/payments', [
            'order_id' => $order->public_id,
            'idempotency_key' => 'pay_attempt_b',
        ]);

        $second->assertStatus(200);
        $this->assertSame($first->json('data.payment.id'), $second->json('data.payment.id'));
        $this->assertSame(1, DB::table('payments')->count());
    }

    /**
     * Сервис отказывает и в обход HTTP-валидации.
     *
     * `initiatePayment()` — единственная точка, которая реально дедуплицирует, и
     * её вызывают не только из контроллера (embed-виджет, админка, скрипты).
     * Проверка обязана стоять там, а не только в правилах запроса: иначе
     * следующий вызывающий снова откроет дыру, просто не продублировав правило.
     */
    public function test_the_service_itself_refuses_a_missing_key(): void
    {
        $order = $this->pendingOrder();

        $this->expectException(ValidationError::class);

        $this->payments->initiatePayment($order->id, []);
    }

    /** Ключ длиннее колонки (VARCHAR(255)) — 422, а не молчаливое обрезание MySQL. */
    public function test_an_overlong_key_is_refused(): void
    {
        $order = $this->pendingOrder();
        $this->actingAsAdmin();

        $this->postJson('/api/v1/payments', [
            'order_id' => $order->public_id,
            'idempotency_key' => str_repeat('k', 256),
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('payments')->count());
    }

    /** Реальный платёж создаётся и получает ключ из запроса — контрольная точка. */
    public function test_a_created_payment_persists_the_supplied_key(): void
    {
        $order = $this->pendingOrder();
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/payments', [
            'order_id' => $order->public_id,
            'idempotency_key' => 'pay_persisted_key',
        ])->assertStatus(201);

        $payment = Payment::query()->findOrFail((int) $response->json('data.payment.id'));

        $this->assertSame('pay_persisted_key', $payment->idempotency_key);
        $this->assertSame($order->id, (int) $payment->order_id);
        $this->assertSame(PaymentStateMachine::PENDING, $payment->status);
        $this->assertStringContainsString('/#/checkout/demo-pay', (string) $payment->payment_url);
    }

    private function pendingOrder(int $quantity = 1): Order
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 10);

        $this->organizationId = $organizationId;

        return $this->orders->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => $quantity]],
        ]);
    }

    private int $organizationId = 0;

    /**
     * Администратор организации — то, что требует `StaffRole::isStaff()`
     * (роль в `user_roles`, а не колонка в `users`). Сотрудник проходит
     * `PaymentController::mayPay()`, поэтому тест не зависит от гостевого
     * `X-Cart-Token`.
     */
    private function actingAsAdmin(): User
    {
        $now = now();

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
            ]);
        }

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $this->organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $this->organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }
}
