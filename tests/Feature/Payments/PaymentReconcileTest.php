<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Domain\TransactionType;
use Nabilet\Modules\Payments\Providers\PaymentProviderInterface;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Tests\Support\ScriptedPaymentProvider;
use Nabilet\Tests\Support\SellableSeatFixtures;
use RuntimeException;
use Tests\TestCase;

/**
 * Сверка зависших платежей с провайдером (аудит R-10).
 *
 * ДЕФЕКТ. Вебхук — единственный штатный сигнал об оплате. Если он не дошёл
 * (сеть, недоступность хоста, откат транзакции на 5xx), платёж навсегда
 * оставался `pending`, заказ — `awaiting_payment`, холд истекал и места уходили
 * в перепродажу. Покупатель, у которого банк деньги списал, не получал ни
 * билета, ни возврата, и замечал это только сам — по факту отсутствия билета.
 * `YooKassaProvider::getPayment()` существовал, но не вызывался ниоткуда.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Команда `payments:reconcile` обязана: заметить такой платёж,
 * опросить провайдера, провести подтверждённую оплату ЧЕРЕЗ ТОТ ЖЕ валидированный
 * путь (сверка суммы, выпуск билетов), быть идемпотентной, не трогать ещё живые
 * платежи и НЕ объявлять платёж неудачным, когда провайдер недоступен.
 */
class PaymentReconcileTest extends TestCase
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

        $this->app->make(PaymentProviderRegistry::class)
            ->register('yookassa', fn (): PaymentProviderInterface => $this->provider);
    }

    /**
     * Потерянное уведомление: провайдер подтверждает оплату — заказ становится paid.
     *
     * Это и есть основной сценарий, ради которого команда существует: деньги у
     * провайдера уже списаны, а у нас заказ ждёт оплаты и холд тикает.
     */
    public function test_a_lost_webhook_is_settled_by_the_reconcile_command(): void
    {
        [$order, $paymentId] = $this->stuckPayment();

        $this->provider->remote = [
            'status' => PaymentStateMachine::SUCCEEDED,
            'amount' => (int) $order->total_amount,
            'currency' => 'RUB',
            'provider_data' => [],
        ];

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(
            PaymentStateMachine::SUCCEEDED,
            DB::table('payments')->where('id', $paymentId)->value('status')
        );
        $this->assertSame(
            OrderStateMachine::PAID,
            DB::table('orders')->where('id', $order->id)->value('status')
        );
        $this->assertSame(
            'succeeded',
            DB::table('orders')->where('id', $order->id)->value('payment_status')
        );
        $this->assertNotNull(
            DB::table('tickets')->where('order_id', $order->id)->first(),
            'подтверждённая оплата обязана выпустить билеты'
        );
    }

    /**
     * Повторный проход не проводит платёж второй раз.
     *
     * Идемпотентность обеспечена схемой: синтезированное уведомление несёт
     * детерминированный `event_id`, поэтому второй проход упирается в UNIQUE
     * `webhook_events` и ничего не делает. Без этого планировщик, дёрнув команду
     * дважды, выпустил бы второй комплект билетов.
     */
    public function test_the_reconcile_command_is_idempotent(): void
    {
        [$order, $paymentId] = $this->stuckPayment();

        $this->provider->remote = [
            'status' => PaymentStateMachine::SUCCEEDED,
            'amount' => (int) $order->total_amount,
            'currency' => 'RUB',
            'provider_data' => [],
        ];

        $this->artisan('payments:reconcile')->assertExitCode(0);
        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('payment_transactions')
                ->where('payment_id', $paymentId)
                ->where('type', TransactionType::CAPTURE)
                ->count(),
            'повторная сверка записала вторую операцию захвата'
        );
        $this->assertSame(
            1,
            DB::table('tickets')->where('order_id', $order->id)->count(),
            'повторная сверка выпустила второй комплект билетов'
        );
    }

    /** Провайдер тоже считает платёж незавершённым — ничего не меняем. */
    public function test_a_still_pending_remote_status_is_left_alone(): void
    {
        [, $paymentId] = $this->stuckPayment();

        $this->provider->remote = [
            'status' => PaymentStateMachine::PENDING,
            'provider_data' => [],
        ];

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(
            PaymentStateMachine::PENDING,
            DB::table('payments')->where('id', $paymentId)->value('status')
        );
        $this->assertSame(1, $this->provider->getPaymentCalls, 'провайдер обязан быть опрошен');
    }

    /**
     * Недоступность провайдера НЕ объявляет платёж неудачным.
     *
     * Исход неизвестен: деньги могли уйти. Пометить такой платёж `failed` —
     * значит освободить место, которое, возможно, уже оплачено, и потерять
     * подтверждённую оплату уже безвозвратно.
     */
    public function test_an_unreachable_provider_does_not_fail_the_payment(): void
    {
        [, $paymentId] = $this->stuckPayment();

        $this->provider->onGetPayment = static function (): array {
            throw new RuntimeException('YooKassa is unreachable');
        };

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(
            PaymentStateMachine::PENDING,
            DB::table('payments')->where('id', $paymentId)->value('status'),
            'недоступность провайдера не должна менять статус платежа'
        );
        $this->assertSame(0, DB::table('webhook_events')->count());
    }

    /**
     * Свежий платёж не опрашивается.
     *
     * Пока окно оплаты (10 минут) не истекло, `pending` — легальное состояние, а
     * не потерянное уведомление. Опрос каждого живого платежа был бы потоком
     * лишних запросов к ЮKassa.
     */
    public function test_a_fresh_payment_is_not_polled(): void
    {
        $order = $this->pendingOrder();

        $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'fresh_payment_1',
        ]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(0, $this->provider->getPaymentCalls, 'свежий платёж не должен опрашиваться');
    }

    /**
     * Платёж без идентификатора у провайдера не опрашивается.
     *
     * Спрашивать нечего: это брошенная попытка, а не потерянное уведомление.
     */
    public function test_a_payment_without_a_provider_id_is_skipped(): void
    {
        $order = $this->pendingOrder();

        $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => null,
        ]);

        DB::table('payments')->update(['created_at' => now()->subHour()]);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(0, $this->provider->getPaymentCalls);
    }

    /**
     * Зависшее уведомление попадает в отчёт.
     *
     * `webhook_events.processed_at IS NULL` — единственный след того, что
     * уведомление пришло и не было обработано. Раньше на это не смотрел никто:
     * строка просто оставалась в таблице.
     */
    public function test_a_stale_unprocessed_webhook_event_is_reported(): void
    {
        DB::table('webhook_events')->insert([
            'provider' => 'yookassa',
            'provider_event_id' => 'lost_event_1',
            'event_name' => 'payment.succeeded',
            'payload_json' => '{}',
            'processed_at' => null,
            'created_at' => now()->subHour(),
        ]);

        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('не обработаны')
            ->assertExitCode(0);
    }

    /** Свежее необработанное уведомление — норма: обработчик ещё может успеть. */
    public function test_a_fresh_unprocessed_webhook_event_is_not_reported(): void
    {
        DB::table('webhook_events')->insert([
            'provider' => 'yookassa',
            'provider_event_id' => 'just_arrived_1',
            'event_name' => 'payment.succeeded',
            'payload_json' => '{}',
            'processed_at' => null,
            'created_at' => now(),
        ]);

        $this->artisan('payments:reconcile')
            ->doesntExpectOutputToContain('не обработаны')
            ->assertExitCode(0);
    }

    /**
     * Оплата по уже отменённому заказу уходит в автовозврат, а не воскрешает заказ.
     *
     * Это сценарий T1 из аудита: холд истёк, заказ отменён, а деньги всё-таки
     * списаны. Сверка обязана провести его ЧЕРЕЗ ТУ ЖЕ проверку, что и вебхук, —
     * иначе «догоняющий» опрос стал бы обходным путём для оплаты мёртвого
     * заказа.
     */
    public function test_a_settlement_for_a_terminal_order_does_not_revive_it(): void
    {
        [$order, $paymentId] = $this->stuckPayment();

        DB::table('orders')->where('id', $order->id)->update([
            'status' => OrderStateMachine::CANCELLED,
        ]);

        $this->provider->remote = [
            'status' => PaymentStateMachine::SUCCEEDED,
            'amount' => (int) $order->total_amount,
            'currency' => 'RUB',
            'provider_data' => [],
        ];

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame(
            OrderStateMachine::CANCELLED,
            DB::table('orders')->where('id', $order->id)->value('status'),
            'отменённый заказ не должен воскреснуть'
        );
        $this->assertSame(
            0,
            DB::table('tickets')->where('order_id', $order->id)->count(),
            'по отменённому заказу билеты не выпускаются'
        );
        $this->assertGreaterThan(
            0,
            DB::table('refunds')->where('payment_id', $paymentId)->count(),
            'подтверждённая оплата по мёртвому заказу обязана уйти в возврат'
        );
    }

    // ------------------------------------------------------------------
    // Вспомогательное
    // ------------------------------------------------------------------

    /**
     * Заказ с платежом, который висит дольше окна сверки.
     *
     * @return array{0: Order, 1: int} [заказ, id платежа]
     */
    private function stuckPayment(): array
    {
        $order = $this->pendingOrder();

        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'stuck_' . Str::random(8),
        ]);

        DB::table('payments')->where('id', $payment->id)->update([
            'created_at' => now()->subMinutes(30),
        ]);

        return [$order, (int) $payment->id];
    }

    private function pendingOrder(int $quantity = 1): Order
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 10);

        return $this->orders->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => $quantity]],
        ]);
    }
}
