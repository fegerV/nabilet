<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Providers\PaymentProviderInterface;
use Nabilet\Modules\Payments\Providers\YooKassaProvider;
use Nabilet\Modules\Payments\Services\PaymentProviderRegistry;
use Nabilet\Modules\Payments\Services\PaymentService;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Согласованность нормализатора провайдера с диспатчером сервиса.
 *
 * ДЕФЕКТ, КОТОРЫЙ ЗДЕСЬ ЗАКРЫВАЕТСЯ. Словарь событий живёт в ДВУХ местах:
 * `YooKassaProvider::handleWebhook()` переводит событие ЮKassa в наше имя, а
 * `PaymentService::processWebhook()` диспатчит по этому имени. В провайдере
 * стояло `'payment.canceled' => 'payment.failed'` — то есть отменённый платёж
 * превращался в неудачный ДО того, как дойдёт до обработчика.
 *
 * Почему это не поймал существующий тест: `PaymentWritePathTest` зовёт
 * `processWebhook()` напрямую с сырым payload, минуя нормализатор провайдера.
 * Живой путь (`PaymentController::webhook()`) тоже пока идёт мимо — но день,
 * когда вебхуки начнут ходить через провайдера, ближе, чем кажется, и тогда
 * A12 (отменённый платёж — отдельное терминальное состояние) умер бы МОЛЧА:
 * оба события дают 200, статус просто другой.
 *
 * Поэтому здесь проверяется не «метод возвращает строку», а ИНВАРИАНТ: всё,
 * что нормализатор способен выдать, диспатчер обязан понимать, и различение
 * `canceled`/`failed` обязано дожить до обработчика.
 */
class PaymentProviderVocabularyTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    private OrderService $orders;
    private PaymentService $payments;
    private PaymentProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = $this->app->make(OrderService::class);
        $this->payments = $this->app->make(PaymentService::class);

        config([
            'nabilet.payment.yookassa.shop_id' => 'test-shop',
            'nabilet.payment.yookassa.secret_key' => 'test-secret',
        ]);

        $this->provider = $this->app->make(PaymentProviderRegistry::class)->make('yookassa');
    }

    /**
     * Строка, которую я правил: `payment.canceled` не имеет права становиться
     * `payment.failed` по дороге к обработчику.
     */
    public function test_the_normalizer_preserves_payment_canceled(): void
    {
        $normalized = $this->provider->handleWebhook([
            'event' => 'payment.canceled',
            'object' => [
                'id' => 'yk-canceled-1',
                'status' => 'canceled',
                'cancellation_details' => ['reason' => 'payment_canceled', 'party' => 'yoo_money'],
            ],
        ]);

        $this->assertIsArray($normalized, 'нормализатор отверг корректную нагрузку');
        $this->assertSame('payment.canceled', $normalized['event_type']);
        $this->assertSame('yk-canceled-1', $normalized['payment_id']);
    }

    /**
     * Сквозь нормализатор, а не вокруг него: отменённый платёж обязан
     * оказаться `canceled` в базе, а заказ — в `payment_failed` (машина
     * разрешает ретрай другой картой).
     *
     * Тест собирает путь так, как его собрал бы будущий рефакторинг: сырой
     * payload → `handleWebhook()` → `processWebhook()`. Именно на этом стыке
     * словари и разъехались.
     */
    public function test_a_canceled_payment_survives_the_normalizer_end_to_end(): void
    {
        $order = $this->pendingOrder();
        $payment = $this->payments->createPayment($order->id, [
            'provider' => 'yookassa',
            'provider_payment_id' => 'yk-canceled-2',
        ]);

        $payload = [
            'event' => 'payment.canceled',
            'object' => [
                'id' => 'yk-canceled-2',
                'status' => 'canceled',
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
                'cancellation_details' => ['reason' => 'payment_canceled', 'party' => 'yoo_money'],
            ],
        ];

        $normalized = $this->provider->handleWebhook($payload);
        $this->assertIsArray($normalized);

        $this->payments->processWebhook('yookassa', [
            'event' => $normalized['event_type'],
            'object' => $normalized['data'],
            'event_id' => $normalized['event_id'],
        ]);

        $this->assertSame(
            PaymentStateMachine::CANCELED,
            DB::table('payments')->where('id', $payment->id)->value('status'),
            'A12: отмена не должна схлопываться в failed — иначе отменённый платёж неотличим от неудачного'
        );

        $this->assertSame(
            OrderStateMachine::PAYMENT_FAILED,
            DB::table('orders')->where('id', $order->id)->value('status'),
            'Отмена обязана двигать заказ: иначе он навсегда висит в awaiting_payment'
        );
    }

    /**
     * `payment.failed` — НЕ событие ЮKassa, и нормализатор обязан его отвергать.
     *
     * У ЮKassa неудачный платёж приходит как `payment.canceled` с
     * `cancellation_details.reason`; события `payment.failed` в её API нет.
     * Внутреннее имя `payment.failed` существует, но его порождает СВЕРКА
     * (`ReconcilePaymentsCommand::eventFor()`), а не провайдер.
     *
     * Почему это важно фиксировать тестом: ровно эту разницу и стёрла прежняя
     * правка `'payment.canceled' => 'payment.failed'` — она записала ВНУТРЕННЕЕ
     * имя в таблицу перевода ПРОВАЙДЕРОВОГО события, и оба словаря съехались.
     */
    public function test_payment_failed_is_not_a_yookassa_event(): void
    {
        $normalized = $this->provider->handleWebhook([
            'event' => 'payment.failed',
            'object' => ['id' => 'yk-nope', 'status' => 'failed'],
        ]);

        $this->assertNull($normalized, 'payment.failed — не событие ЮKassa');
    }

    /**
     * ИНВАРИАНТ, а не единичный случай: у каждого события, которое нормализатор
     * способен выдать, в диспатчере есть ветка. Иначе оно упало бы в `default`
     * → RuntimeException → откат claim → бесконечный ретрай провайдера.
     *
     * `payment.waiting_for_capture` — исключение из правила «обработчик есть»:
     * оно осознанно объявлено обработанным без изменения состояния (R-14).
     */
    public function test_every_event_the_normalizer_emits_is_dispatched(): void
    {
        $dispatched = [
            'payment.succeeded',
            'payment.failed',
            'payment.canceled',
            'refund.succeeded',
            'refund.failed',
            'payment.waiting_for_capture',
        ];

        $payloads = [
            'payment.succeeded' => ['event' => 'payment.succeeded', 'object' => ['id' => 'yk-1', 'status' => 'succeeded']],
            'payment.waiting_for_capture' => ['event' => 'payment.waiting_for_capture', 'object' => ['id' => 'yk-2', 'status' => 'waiting_for_capture']],
            'payment.canceled' => ['event' => 'payment.canceled', 'object' => ['id' => 'yk-3', 'status' => 'canceled']],
            'refund.succeeded' => ['event' => 'refund.succeeded', 'object' => ['id' => 'rf-1', 'status' => 'succeeded']],
            'refund.failed' => ['event' => 'refund.failed', 'object' => ['id' => 'rf-2', 'status' => 'failed']],
        ];

        foreach ($payloads as $yooKassaEvent => $payload) {
            $normalized = $this->provider->handleWebhook($payload);

            $this->assertIsArray($normalized, "нормализатор отверг событие {$yooKassaEvent}");
            $this->assertContains(
                $normalized['event_type'],
                $dispatched,
                "событие {$yooKassaEvent} нормализовано в «{$normalized['event_type']}», "
                . 'которое PaymentService не диспатчит — провайдер будет повторять его вечно'
            );
        }
    }

    /**
     * `mapStatus()` — вторая копия словаря платежа, и она писалась руками.
     * `payments.status` ограничен `ck_payments_status`, поэтому значение вне
     * словаря — это CHECK violation на записи, то есть 500 вместо внятной
     * ошибки. Проверяем через ПУБЛИЧНЫЙ `getPayment()` с подставным HTTP:
     * именно так метод вызывается на самом деле.
     *
     * @return array<string, array<int, string>>
     */
    public static function providerStatusProvider(): array
    {
        return [
            'pending' => ['pending'],
            'waiting_for_capture' => ['waiting_for_capture'],
            'succeeded' => ['succeeded'],
            'canceled' => ['canceled'],
            'failed' => ['failed'],
            'неизвестный статус' => ['something_new_from_the_provider'],
        ];
    }

    #[DataProvider('providerStatusProvider')]
    public function test_get_payment_maps_into_the_payment_vocabulary(string $remoteStatus): void
    {
        Http::fake([
            '*/payments/yk-map-*' => Http::response([
                'id' => 'yk-map-1',
                'status' => $remoteStatus,
                'amount' => ['value' => '50000.00', 'currency' => 'RUB'],
                'created_at' => '2026-01-01T00:00:00.000Z',
            ], 200),
        ]);

        $remote = $this->provider->getPayment('yk-map-1');

        // Словарь читаем из схемы, а не из константы: если `ck_payments_status`
        // когда-нибудь расширится, тест проверит метод против НЕГО, а не против
        // прежнего списка, и молчаливое расхождение станет падением.
        $allowed = $this->paymentsStatusVocabulary();

        $this->assertContains(
            $remote['status'],
            $allowed,
            "статус «{$remote['status']}» вне словаря payments.status"
        );
    }

    /**
     * Словарь `ck_payments_status` прямо из базы.
     *
     * @return list<string>
     */
    private function paymentsStatusVocabulary(): array
    {
        $row = DB::selectOne(
            "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'ck_payments_status'"
        );

        $this->assertNotNull($row, 'ck_payments_status не найдена в information_schema');

        // MySQL отдаёт CHECK_CLAUSE с экранированными кавычками:
        // (`status` in (_utf8mb4\'pending\',_utf8mb4\'succeeded\',...))
        // Снимаем экранирование, иначе регулярка выхватывает «pending\».
        $clause = str_replace("\\'", "'", (string) $row->CHECK_CLAUSE);

        preg_match_all("/'([^']+)'/", $clause, $matches);

        return $matches[1];
    }

    /**
     * Заказ в `pending` с одним платежом — отправная точка для вебхука.
     *
     * Копия хелпера из `PaymentWritePathTest`: тот объявлен `private`, а
     * тащить сюда общее имя ради двух строк значило бы связать два файла
     * через рефакторинг, который никому не нужен.
     */
    private function pendingOrder(): \Nabilet\Modules\Orders\Models\Order
    {
        [$organizationId, , , $inventoryId] = $this->seedSellableSeat(available: 10);

        return $this->orders->createOrder([
            'organization_id' => $organizationId,
            'customer_email' => 'buyer@example.com',
            'items' => [['inventory_item_id' => $inventoryId, 'quantity' => 1]],
        ]);
    }
}
