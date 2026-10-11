<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Providers\PaymentProviderInterface;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Tests\Support\ScriptedPaymentProvider;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Границы инициации платежа (аудит R-02, R-05, R-09, R-12).
 *
 * Три дефекта в одном методе `PaymentService::initiatePayment()`, и все три
 * видны только на стыке «наша БД ↔ внешний шлюз»:
 *
 *  R-02. Проверка «есть ли активный платёж по заказу» выполнялась без
 *        `lockForUpdate()`. Два одновременных `POST /payments` с РАЗНЫМИ
 *        ключами идемпотентности оба видели «активных нет» и создавали по
 *        pending-платежу. UNIQUE на ключе не спасает — ключи разные. Если
 *        покупатель успевал оплатить обе ссылки, деньги списывались дважды.
 *  R-05. Статус заказа не проверялся вообще. Отменённый, просроченный, уже
 *        возвращённый или уже оплаченный заказ получал платёжную ссылку:
 *        покупатель платил, вебхук уходил в автовозврат, а по уже оплаченному
 *        заказу новая ссылка означала второе списание.
 *  R-09. Вызов провайдера стоял ВНУТРИ `DB::transaction()`. При сетевом
 *        таймауте шлюз платёж создавал, а наша транзакция откатывалась — у
 *        провайдера платёж есть, у нас строки нет. Плюс блокировка строки
 *        заказа (R-02) держалась бы всё время сетевого вызова.
 *
 * Гонку (R-02) тест воспроизводит ДЕТЕРМИНИРОВАННО, без потоков: провайдеру
 * даётся сценарий, который выполняется ровно в промежутке между «решили
 * создавать» и «записали» — то есть в том самом окне, в котором реальный
 * параллельный запрос успевает вставить свой платёж.
 */
class PaymentInitiationGuardsTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private OrderService $orders;
    private PaymentService $payments;
    private ScriptedPaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nabilet.payment.demo_mode' => true]);

        $this->orders = $this->app->make(OrderService::class);
        $this->payments = $this->app->make(PaymentService::class);
        $this->provider = new ScriptedPaymentProvider();

        // Провайдер подменяется фабрикой в реестре-синглтоне, поэтому сервис,
        // собранный выше, видит уже подменённого провайдера. Имя ('yookassa')
        // оставлено настоящим: `payments.provider` — NOT NULL и участвует в
        // проверках схемы, подменять его значение незачем.
        $this->app->make(PaymentProviderRegistry::class)
            ->register('yookassa', fn (): PaymentProviderInterface => $this->provider);
    }

    // ------------------------------------------------------------------
    // R-05: заказ обязан допускать оплату
    // ------------------------------------------------------------------

    /** Отменённый заказ не должен получать платёжную ссылку. */
    public function test_a_cancelled_order_cannot_be_paid(): void
    {
        $order = $this->pendingOrder();

        $this->assertNotPayable($order, OrderStateMachine::CANCELLED);
    }

    /** Просроченный заказ (истекло 10-минутное окно) — тоже. */
    public function test_an_expired_order_cannot_be_paid(): void
    {
        $order = $this->pendingOrder();

        $this->assertNotPayable($order, OrderStateMachine::EXPIRED);
    }

    /**
     * Уже оплаченный заказ — самое опасное: новая ссылка = второе списание.
     *
     * Ключ здесь ДРУГОЙ, поэтому ветка «идемпотентный повтор» не срабатывает и
     * решение принимает именно проверка оплачиваемости.
     */
    public function test_an_already_paid_order_cannot_receive_a_second_link(): void
    {
        $order = $this->pendingOrder();

        $this->assertNotPayable($order, OrderStateMachine::PAID);
    }

    /** Полностью возвращённый заказ оплачивать нечем и незачем. */
    public function test_a_refunded_order_cannot_be_paid(): void
    {
        $order = $this->pendingOrder();

        $this->assertNotPayable($order, OrderStateMachine::REFUNDED);
    }

    /**
     * Отклонённая карта НЕ запрещает повторную оплату.
     *
     * `payment_failed` — не терминальный статус ровно для этого сценария: клиент
     * обязан иметь возможность заплатить другой картой. Если бы проверка
     * оплачиваемости запрещала и его, фикс R-05 закрыл бы оплату для всех, у
     * кого карта не прошла.
     */
    public function test_an_order_with_a_failed_payment_may_be_paid_again(): void
    {
        $order = $this->pendingOrder();

        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStateMachine::PAYMENT_FAILED,
        ]);

        $result = $this->payments->initiatePayment($order->id, ['idempotency_key' => 'retry_after_decline']);

        $this->assertTrue($result['created']);
        $this->assertSame(1, DB::table('payments')->where('order_id', $order->id)->count());
    }

    /** Заказ в ожидании оплаты — норма: ссылку можно выдать повторно. */
    public function test_an_awaiting_payment_order_may_be_paid(): void
    {
        $order = $this->pendingOrder();

        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStateMachine::AWAITING_PAYMENT,
        ]);

        $result = $this->payments->initiatePayment($order->id, ['idempotency_key' => 'awaiting_ok']);

        $this->assertTrue($result['created']);
    }

    /**
     * Идемпотентный повтор важнее проверки оплачиваемости.
     *
     * Клиент, нажавший «Оплатить» второй раз по уже оплаченному заказу, обязан
     * получить свой платёж (200 «оплата уже прошла»), а не 409 «заказ нельзя
     * оплатить»: 409 не говорит ему, что деньги уже списаны. Поэтому ветка
     * повтора по ключу стоит ВЫШЕ проверки статуса заказа.
     */
    public function test_a_replay_of_a_paid_order_still_returns_its_payment(): void
    {
        $order = $this->pendingOrder();

        $first = $this->payments->initiatePayment($order->id, ['idempotency_key' => 'paid_replay']);

        DB::table('payments')->where('id', $first['payment']->id)->update([
            'status' => PaymentStateMachine::SUCCEEDED,
            'paid_at' => now(),
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStateMachine::PAID,
        ]);

        $replay = $this->payments->initiatePayment($order->id, ['idempotency_key' => 'paid_replay']);

        $this->assertFalse($replay['created'], 'повтор по тому же ключу обязан вернуть существующий платёж');
        $this->assertSame($first['payment']->id, $replay['payment']->id);
        $this->assertSame(1, DB::table('payments')->where('order_id', $order->id)->count());
    }

    // ------------------------------------------------------------------
    // R-09: сеть вне транзакции
    // ------------------------------------------------------------------

    /**
     * Провайдер вызывается вне транзакции.
     *
     * Сравнивается ГЛУБИНА транзакции: вызов обязан произойти на той же глубине,
     * что и вызывающий код, а не на уровень глубже. Абсолютное значение здесь
     * смысла не имеет — `RefreshDatabase` держит внешнюю транзакцию открытой,
     * поэтому «ноль» в тестах недостижим, а вот «на один уровень глубже» — это
     * ровно признак того, что вызов ушёл внутрь `DB::transaction()`.
     *
     * По коду видно, где стоит вызов, но именно этот вызов «переезжает» обратно
     * внутрь транзакции при любом рефакторинге, потому что внутри него удобнее:
     * всё под рукой. Цена возврата — потерянный платёж при таймауте и блокировка
     * строки заказа на всё время сетевого ответа.
     */
    public function test_the_provider_is_called_outside_a_transaction(): void
    {
        $order = $this->pendingOrder();

        $callerDepth = DB::transactionLevel();

        $this->payments->initiatePayment($order->id, ['idempotency_key' => 'outside_tx']);

        $this->assertSame(1, $this->provider->createCalls);
        $this->assertSame(
            $callerDepth,
            $this->provider->transactionLevelAtCall,
            'вызов провайдера ушёл внутри DB::transaction()'
        );
    }

    /**
     * Если заказ стал неоплачиваемым, пока шёл сетевой вызов, платёж не пишется.
     *
     * Между фазой проверки и фазой записи статус заказа может измениться: холд
     * истёк, оператор отменил заказ, клиент нажал «Отменить». Повторная проверка
     * обязана стоять и в фазе записи, иначе защита R-05 закрывает только
     * «спокойный» сценарий и пропускает гонку.
     */
    public function test_the_order_status_is_rechecked_before_writing_the_payment(): void
    {
        $order = $this->pendingOrder();

        // Ровно то окно, в котором реальный параллельный запрос отменяет заказ.
        $this->provider->onCreate = function () use ($order): void {
            DB::table('orders')->where('id', $order->id)->update([
                'status' => OrderStateMachine::CANCELLED,
            ]);
        };

        try {
            $this->payments->initiatePayment($order->id, ['idempotency_key' => 'cancelled_during_call']);

            $this->fail('платёж создан по заказу, отменённому во время вызова провайдера');
        } catch (ConflictError $e) {
            $this->assertSame('ORDER_NOT_PAYABLE', $e->errorCode);
        }

        $this->assertSame(0, DB::table('payments')->count());
    }

    // ------------------------------------------------------------------
    // R-02: гонка двух параллельных инициаций
    // ------------------------------------------------------------------

    /**
     * Платёж параллельного запроса побеждает, второй строки не появляется.
     *
     * Сценарий ровно тот, что описан в аудите: два запроса с разными ключами
     * доходят до записи одновременно. Здесь параллельный запрос «выигрывает»
     * детерминированно — он вставляет свой pending-платёж в момент сетевого
     * вызова первого. Фаза записи обязана это заметить и вернуть чужой платёж,
     * а не создать второй активный.
     *
     * Раньше здесь появлялись две строки: проверка активного платежа была
     * прочитана ДО того, как конкурирующая строка появилась, и ничем не
     * перепроверялась.
     */
    public function test_a_payment_created_by_a_concurrent_request_wins(): void
    {
        $order = $this->pendingOrder();

        $this->provider->onCreate = function () use ($order): void {
            DB::table('payments')->insert([
                'public_id' => (string) Str::ulid()->toBase32(),
                'order_id' => $order->id,
                'provider' => 'yookassa',
                'provider_payment_id' => 'racer_payment_1',
                'amount' => (int) $order->total_amount,
                'currency' => 'RUB',
                'status' => PaymentStateMachine::PENDING,
                'payment_url' => 'https://pay.example.test/racer',
                'idempotency_key' => 'the_other_requests_key',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        $result = $this->payments->initiatePayment($order->id, ['idempotency_key' => 'this_requests_key']);

        $this->assertFalse($result['created'], 'победить должен платёж параллельного запроса');
        $this->assertSame('racer_payment_1', $result['payment']->provider_payment_id);
        $this->assertSame(
            1,
            DB::table('payments')->where('order_id', $order->id)->count(),
            'на заказ не должно быть двух активных платежей'
        );
    }

    /**
     * Инициация действительно блокирует строку заказа.
     *
     * Проверяется не «намерение в коде», а фактический SQL: в потоке запросов
     * обязан быть `SELECT ... FOR UPDATE` по `orders`. Без блокировки проверка
     * «активного платежа нет» читает снимок, который конкурент меняет сразу
     * после чтения, — именно так и появлялись два pending-платежа на заказ.
     *
     * Прямое удержание блокировки между двумя соединениями здесь не проверяется:
     * `RefreshDatabase` держит тест в одной транзакции, а `DB::connection()`
     * возвращает один и тот же объект, поэтому «второе соединение» было бы тем
     * же самым.
     */
    public function test_initiation_locks_the_order_row(): void
    {
        $order = $this->pendingOrder();

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        $this->payments->initiatePayment($order->id, ['idempotency_key' => 'locks_the_order']);

        $locked = array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'orders') && str_contains($sql, 'for update')
        );

        $this->assertNotEmpty($locked, 'инициация платежа не берёт блокировку строки заказа');
    }

    // ------------------------------------------------------------------
    // R-12: место не «продаётся», если машина это запрещает
    // ------------------------------------------------------------------

    /**
     * Выключенное для сеанса место не становится проданным.
     *
     * `disabled` означает «этого места в зале нет». Прямая запись
     * `update(['status' => 'sold'])` превращала его в проданное — то есть в зале
     * появлялось место, которого не существует, и оно уходило в отчётность как
     * реализованное. `InventoryItemStateMachine` переход `disabled → sold`
     * запрещает, и запрет обязан соблюдаться в пути оплаты.
     */
    public function test_a_disabled_inventory_item_is_not_marked_sold(): void
    {
        Log::spy();

        $order = $this->pendingOrder();

        $inventoryItemId = (int) DB::table('order_items')->where('order_id', $order->id)->value('inventory_item_id');
        DB::table('inventory_items')->where('id', $inventoryItemId)->update(['status' => 'disabled']);

        $this->paySucceeded($order);

        $this->assertSame(
            'disabled',
            DB::table('inventory_items')->where('id', $inventoryItemId)->value('status'),
            'запрещённый переход disabled → sold всё равно был записан'
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (...$args): bool => isset($args[0])
                && is_string($args[0])
                && str_contains($args[0], 'cannot be marked sold'))
            ->once();
    }

    /**
     * Место в статусе `available` продаётся как и раньше.
     *
     * Контроль к предыдущему тесту: проверка машины не должна отвергать легальный
     * переход. Заказ, созданный напрямую через `OrderService`, оставляет место в
     * `available` (статус `held` ставит корзина), и `available → sold` машиной
     * разрешён.
     */
    public function test_an_available_inventory_item_is_marked_sold_on_payment(): void
    {
        $order = $this->pendingOrder();

        $inventoryItemId = (int) DB::table('order_items')->where('order_id', $order->id)->value('inventory_item_id');
        $this->assertSame('available', DB::table('inventory_items')->where('id', $inventoryItemId)->value('status'));

        $this->paySucceeded($order);

        $this->assertSame('sold', DB::table('inventory_items')->where('id', $inventoryItemId)->value('status'));
    }

    // ------------------------------------------------------------------
    // R-13: демо-подтверждение оплаты не должно работать на проде
    // ------------------------------------------------------------------

    /**
     * В production эндпоинт отказывает даже при включённом флаге.
     *
     * `POST /payments/demo-pay` анонимен и подтверждает оплату — то есть при
     * `PAYMENT_DEMO_MODE=true` на проде любой желающий «оплачивает» любой заказ
     * одним запросом. Сторож `ProductionSafetyProvider` не даёт приложению
     * подняться с этим флагом, но барьер в самом эндпоинте не должен зависеть от
     * того, зарегистрирован ли провайдер и верно ли названо окружение.
     */
    public function test_demo_pay_is_refused_in_production_even_with_the_flag_on(): void
    {
        $originalEnv = (string) $this->app['env'];
        $this->app['env'] = 'production';
        config(['nabilet.payment.demo_mode' => true]);

        try {
            $response = $this->postJson('/api/v1/payments/demo-pay', ['payment_id' => 'demo_whatever']);

            $response->assertStatus(403);
            $response->assertJsonPath('error.code', 'DEMO_FORBIDDEN');
        } finally {
            $this->app['env'] = $originalEnv;
        }
    }

    /** Вне production барьер окружения не мешает: запрос доходит до поиска платежа. */
    public function test_demo_pay_passes_the_environment_barrier_outside_production(): void
    {
        config(['nabilet.payment.demo_mode' => true]);

        $response = $this->postJson('/api/v1/payments/demo-pay', ['payment_id' => 'demo_missing']);

        $this->assertNotSame(
            'DEMO_FORBIDDEN',
            $response->json('error.code'),
            'барьер окружения сработал вне production'
        );
    }

    /** Выключенный флаг — отказ независимо от окружения (базовое поведение). */
    public function test_demo_pay_is_disabled_when_the_flag_is_off(): void
    {
        config(['nabilet.payment.demo_mode' => false]);

        $response = $this->postJson('/api/v1/payments/demo-pay', ['payment_id' => 'demo_whatever']);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'DEMO_DISABLED');
    }

    // ------------------------------------------------------------------
    // Вспомогательное
    // ------------------------------------------------------------------

    /** Заказ, который обязан получить 409 ORDER_NOT_PAYABLE вместо платёжной ссылки. */
    private function assertNotPayable(Order $order, string $status): void
    {
        DB::table('orders')->where('id', $order->id)->update(['status' => $status]);

        try {
            $this->payments->initiatePayment($order->id, ['idempotency_key' => 'guard_' . $status]);

            $this->fail("заказ в статусе {$status} получил платёжную ссылку");
        } catch (ConflictError $e) {
            $this->assertSame('ORDER_NOT_PAYABLE', $e->errorCode);
            $this->assertSame($status, $e->context['status'] ?? null);
        }

        $this->assertSame(0, DB::table('payments')->count(), 'платёж по неоплачиваемому заказу создан');
        $this->assertSame(0, $this->provider->createCalls, 'провайдер не должен вызываться для неоплачиваемого заказа');
    }

    /** Довести заказ до `paid` через настоящий вебхук (единственный легальный путь). */
    private function paySucceeded(Order $order): void
    {
        $providerPaymentId = 'scripted_paid_' . Str::random(8);

        $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => $providerPaymentId,
        ]);

        $this->payments->processWebhook('yookassa', [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => $providerPaymentId,
                'status' => 'succeeded',
                'amount' => [
                    'value' => number_format((int) $order->total_amount / 100, 2, '.', ''),
                    'currency' => 'RUB',
                ],
            ],
        ]);

        $this->assertSame(
            OrderStateMachine::PAID,
            DB::table('orders')->where('id', $order->id)->value('status'),
            'предусловие теста: заказ обязан стать paid'
        );
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
}
