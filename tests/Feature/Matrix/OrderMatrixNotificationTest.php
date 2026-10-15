<?php

declare(strict_types=1);

namespace Tests\Feature\Matrix;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Matrix\Domain\MatrixNotice;
use Nabilet\Modules\Matrix\Jobs\SendMatrixMessageJob;
use Nabilet\Modules\Matrix\Services\MatrixClient;
use Nabilet\Modules\Matrix\Services\MatrixSettings;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Уведомление администраторов об оплате заказа — в комнату Matrix.
 *
 * ЗАЩИЩАЕТСЯ ПОЛНЫЙ КОНТУР, А НЕ ОДНА ФУНКЦИЯ
 *
 *   1. ОПЛАТА → ПОСТАНОВКА. Переход заказа в `paid` будит
 *      `OrderMatrixObserver`, и задача уходит в очередь с `afterCommit()`:
 *      уведомление не должно отправляться из откатившейся транзакции.
 *   2. ВЫКЛЮЧЕННЫЙ КАНАЛ МОЛЧИТ. Канал выключен или уведомления о заказах
 *      сняты — задачи нет вовсе. Это важно: «не настроено» не должно копить в
 *      очереди заведомо падающие задачи.
 *   3. НЕПОЛНАЯ НАСТРОЙКА МОЛЧИТ. Канал включён, но адрес/комната/токен не
 *      заполнены — тоже без задачи.
 *   4. ТРАНСПОРТ РЕАЛЬНО ШЛЁТ. Задача делает `PUT` в Client-Server API
 *      Synapse с Bearer-токеном и телом `m.text` — проверяется `Http::fake`.
 *
 * ПОЧЕМУ `Bus::fake()`
 *   Очередь в тесте не крутится. Фейк перехватывает постановку, поэтому тест
 *   видит ФАКТ и содержимое задачи, но не выполняет её (кроме отдельного теста
 *   транспорта, где задача прогоняется вручную).
 */
class OrderMatrixNotificationTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
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

    private function configure(bool $enabled = true, bool $notifyOrders = true): void
    {
        app(MatrixSettings::class)->save([
            MatrixSettings::KEY_HOMESERVER => 'https://matrix.example.org',
            MatrixSettings::KEY_ROOM_ID => '!room:example.org',
            MatrixSettings::KEY_ACCESS_TOKEN => 'matrix-token',
            MatrixSettings::KEY_ENABLED => $enabled,
            MatrixSettings::KEY_NOTIFY_ORDERS => $notifyOrders,
        ]);
    }

    /**
     * Оплаченный заказ покупателя — источник уведомления.
     *
     * Именно переход в `paid` будит `OrderMatrixObserver`, поэтому возвращаем
     * свежий заказ уже в статусе оплаты.
     */
    private function paidOrder(): Order
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 1);

        $service = app(OrderService::class);
        $buyer = $this->user('buyer@example.com');

        $order = $service->createOrder([
            'organization_id' => $organizationId,
            'user_id' => $buyer->id,
            'customer_email' => $buyer->email,
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);

        $service->markAwaitingPayment($order);
        $service->markPaid($order->fresh());

        return $order->fresh();
    }

    // ── 1. оплата ставит уведомление ─────────────────────────────────────────

    public function test_a_paid_order_queues_an_admin_notification(): void
    {
        $this->configure();

        $order = $this->paidOrder();

        Bus::assertDispatched(SendMatrixMessageJob::class, function (SendMatrixMessageJob $job) use ($order): bool {
            return $job->afterCommit === true
                && str_contains($job->body, (string) $order->order_number)
                && str_contains($job->body, 'Сумма:')
                // Название мероприятия приходит из связанного события, а не из
                // конфига — иначе администратор не понял бы, что продано.
                && str_contains($job->body, 'Тестовое событие');
        });
    }

    // ── 2. выключенный канал молчит ──────────────────────────────────────────

    public function test_a_disabled_channel_sends_nothing(): void
    {
        $this->configure(enabled: false);

        $this->paidOrder();

        Bus::assertNotDispatched(SendMatrixMessageJob::class);
    }

    public function test_order_notifications_can_be_switched_off(): void
    {
        // Канал включён, но администратор снял галочку «уведомлять об оплате»:
        // задача не ставится, хотя канал рабочий.
        $this->configure(enabled: true, notifyOrders: false);

        $this->paidOrder();

        Bus::assertNotDispatched(SendMatrixMessageJob::class);
    }

    // ── 3. неполная настройка молчит ─────────────────────────────────────────

    public function test_an_incomplete_configuration_sends_nothing(): void
    {
        // Канал включён, но токена нет — отправка невозможна, и задача не
        // должна копиться в очереди, чтобы падать в воркере.
        app(MatrixSettings::class)->save([
            MatrixSettings::KEY_HOMESERVER => 'https://matrix.example.org',
            MatrixSettings::KEY_ROOM_ID => '!room:example.org',
            MatrixSettings::KEY_ENABLED => true,
            MatrixSettings::KEY_NOTIFY_ORDERS => true,
        ]);

        $this->paidOrder();

        Bus::assertNotDispatched(SendMatrixMessageJob::class);
    }

    // ── 4. транспорт ─────────────────────────────────────────────────────────

    public function test_the_job_posts_a_text_message_to_the_room(): void
    {
        Http::fake(['*' => Http::response(['event_id' => '$event'], 200)]);

        $this->configure();

        (new SendMatrixMessageJob(MatrixNotice::test()))->handle(app(MatrixClient::class));

        Http::assertSent(function ($request): bool {
            return $request->method() === 'PUT'
                && str_starts_with($request->url(), 'https://matrix.example.org/_matrix/client/v3/rooms/')
                && str_contains($request->url(), '/send/m.room.message/')
                && $request->hasHeader('Authorization', 'Bearer matrix-token')
                && ($request['msgtype'] ?? null) === 'm.text'
                && ($request['body'] ?? null) === MatrixNotice::test();
        });
    }

    public function test_the_job_surfaces_a_synapse_failure(): void
    {
        Http::fake(['*' => Http::response(['errcode' => 'M_UNKNOWN_TOKEN'], 401)]);

        $this->configure();

        $this->expectException(\Nabilet\Core\Errors\DomainRuleViolation::class);

        (new SendMatrixMessageJob(MatrixNotice::test()))->handle(app(MatrixClient::class));
    }
}
