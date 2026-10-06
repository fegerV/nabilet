<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Services;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\StateMachine\StateMachine;
use Nabilet\Core\Support\HoldGrace;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\SeatHold;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Repositories\PaymentRepository;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Inventory\Services\HoldSweeper;
use Nabilet\Modules\Inventory\Services\SeatHoldLifecycle;
use Nabilet\Modules\Tickets\Services\TicketService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Оркестрация платежей: инициация у провайдера, обработка вебхуков,
 * подтверждение/отказ платежа и связанные переходы заказа.
 *
 * Рефакторинг (P2):
 *  - зависимости внедряются явно через конструктор — вместо service-locator
 *    (`app(YooKassaProvider::class)`, `class_exists(...) ? app(TicketService)`),
 *    который прятал граф зависимостей и ронял сервис на рантайме;
 *  - выбор провайдера — через PaymentProviderRegistry (чинит битый match +
 *    getProvider с неверными FQCN `Payments\Payments\Providers\...`);
 *  - логика возвратов вынесена в RefundService — сервис больше не совмещает
 *    платежи, вебхуки и refunds; публичный refundPayment() оставлен тонким
 *    делегатом ради обратной совместимости контроллеров/тестов.
 */
class PaymentService
{
    private StateMachine $machine;

    public function __construct(
        protected PaymentRepository $repository,
        // Две разные роли холда, отсюда две зависимости (P2):
        //  - `$holdLifecycle` — точечная проверка/перевод ОДНОГО холда при
        //    обработке вебхука (`isHoldConvertible`, `markAsConverted`);
        //  - `$holdSweeper` — пакетный проход по всем холдам, который нужно
        //    дёрнуть, когда платёж упал и места надо вернуть немедленно, не
        //    дожидаясь ближайшего запуска Cron.
        // Раньше обе роли жили в одном классе, и вызывающий код не различал их.
        protected SeatHoldLifecycle $holdLifecycle,
        protected HoldSweeper $holdSweeper,
        protected OrderService $orders,
        protected PaymentProviderRegistry $providers,
        protected RefundService $refunds,
        // Без `= null`. Параметр был объявлен как `?TicketService $ticketService = null`,
        // и это НЕ «необязательная зависимость», а тихо выключенный выпуск билетов:
        // `Container::resolveClass()` (vendor/laravel/framework/.../Container.php:1354)
        // при наличии значения по умолчанию и отсутствии явного биндинга возвращает
        // именно это значение, не пытаясь разрешить класс:
        //
        //   if ($parameter->isDefaultValueAvailable() && ! $this->bound($className) && …) {
        //       return $parameter->getDefaultValue();
        //   }
        //
        // То есть `$this->ticketService` был ВСЕГДА null, условие
        // `if ($this->ticketService !== null)` в `settleSucceeded()` не выполнялось
        // никогда, и после успешной оплаты не выпускалось ни одного билета.
        // Проверено на живом стенде: оплата подтверждена (платёж `succeeded`,
        // заказ `paid`), `SELECT COUNT(*) FROM tickets` → 0.
        //
        // `TicketService` контейнер собирает нормально (проверено: `make()` его
        // создаёт), поэтому зависимость делается обязательной — тогда контейнер
        // обязан её разрешить. Раньше её прятали за `= null` из-за
        // `CheckinEvaluator` в конструкторе `TicketService`; тот уже удалён.
        protected TicketService $ticketService,
    ) {
        $this->machine = PaymentStateMachine::make();
    }

    /**
     * Создать платёж у провайдера для заказа и сохранить Payment.
     * Возвращает payment + confirmation_url (редирект на шлюз или demo-pay).
     *
     * @param  array{idempotency_key?: string|null}  $data
     * @return array{payment: Payment, confirmation_url: string|null}
     */
    public function initiatePayment(int $orderId, array $data = []): array
    {
        $order = Order::findOrFail($orderId);
        $providerName = PaymentProviderRegistry::defaultProviderName();

        return DB::transaction(function () use ($orderId, $order, $data, $providerName) {
            // A7: ключ идемпотентности принадлежит связке (provider, key). Если
            // такой ключ уже использован для ДРУГОГО заказа — это ошибка клиента
            // (переиспользование ключа), а не «верни чужой платёж»: молчаливая
            // выдача payment другого заказа отправляла покупателя платить не за
            // тот заказ. 409 IDEMPOTENCY_CONFLICT.
            if (!empty($data['idempotency_key'])) {
                $byKey = Payment::where('provider', $providerName)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();

                if ($byKey !== null && (int) $byKey->order_id !== $orderId) {
                    throw new \Nabilet\Core\Errors\ConflictError(
                        'Idempotency key was already used for another order.',
                        'IDEMPOTENCY_CONFLICT',
                        ['used_for_order_id' => (int) $byKey->order_id],
                    );
                }
            }

            // Идемпотентность: один активный платёж на заказ.
            $existing = Payment::where('order_id', $orderId)
                ->whereIn('status', [PaymentStateMachine::PENDING, PaymentStateMachine::WAITING_FOR_CAPTURE])
                ->first();

            if ($existing) {
                return [
                    'payment' => $existing,
                    'confirmation_url' => $existing->payment_url,
                ];
            }

            $provider = $this->providers->make($providerName);
            $providerResult = $provider->createPayment([
                'order_id' => (string) $orderId,
                'amount' => (int) $order->total_amount,
                'currency' => $order->currency ?? 'RUB',
                'description' => "Order #{$orderId}",
                'success_url' => (string) config('nabilet.payment.yookassa.return_url', config('app.url')),
                'idempotency_key' => $data['idempotency_key'] ?? (string) Str::ulid(),
            ]);

            $payment = $this->createPayment($orderId, [
                'provider' => $providerName,
                'provider_payment_id' => $providerResult['payment_id'],
                'amount' => (int) $order->total_amount,
                'currency' => $order->currency ?? 'RUB',
                'payment_url' => $providerResult['confirmation_url'],
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            return [
                'payment' => $payment,
                'confirmation_url' => $providerResult['confirmation_url'],
            ];
        });
    }

    /**
     * Подтвердить демо-платёж (симулятор шлюза в demo_mode).
     * Ищет payment по provider_payment_id = demo_* и переводит его в succeeded.
     */
    public function confirmDemoPayment(string $providerPaymentId): Payment
    {
        if (!str_starts_with($providerPaymentId, 'demo_')) {
            throw new DomainRuleViolation(
                'Not a demo payment',
                'NOT_DEMO_PAYMENT',
                ['payment_id' => $providerPaymentId],
                422,
            );
        }

        $payment = Payment::where('provider_payment_id', $providerPaymentId)->first();

        if (!$payment) {
            throw new DomainRuleViolation(
                'Demo payment not found',
                'UNKNOWN_PAYMENT',
                ['payment_id' => $providerPaymentId],
                404,
            );
        }

        return $this->processWebhook((string) $payment->provider, [
            'event' => 'payment.succeeded',
            'object' => [
                'id' => $payment->provider_payment_id,
                'created_at' => now()->toIso8601String(),
            ],
            'event_id' => $providerPaymentId . ':demo:confirm:' . Str::ulid(),
        ]);
    }

    /**
     * @param  array{provider?: string, provider_payment_id?: string|null, amount?: int|null,
     *               currency?: string|null, payment_url?: string|null, idempotency_key?: string|null}  $data
     */
    public function createPayment(int $orderId, array $data): Payment
    {
        return DB::transaction(function () use ($orderId, $data) {
            // Идемпотентность по (provider, idempotency_key) — UNIQUE в схеме.
            if (!empty($data['idempotency_key'])) {
                $existing = Payment::where('provider', $data['provider'] ?? null)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $order = Order::findOrFail($orderId);
            $amount = (int) ($data['amount'] ?? $order->total_amount);

            $payment = $this->repository->create([
                'order_id' => $orderId,
                'provider' => $data['provider'] ?? PaymentProviderRegistry::defaultProviderName(),
                'provider_payment_id' => $data['provider_payment_id'] ?? null,
                'amount' => $amount,
                'currency' => $data['currency'] ?? $order->currency,
                'status' => PaymentStateMachine::PENDING,
                'payment_url' => $data['payment_url'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? (string) Str::ulid(),
                'metadata_json' => $data['metadata'] ?? [],
            ]);

            // Create initial transaction record
            $this->repository->addTransaction($payment, [
                'type' => 'authorization',
                'amount' => $amount,
                'status' => 'pending',
            ]);

            // Создание платежа = попытка оплаты: pending -> awaiting_payment.
            if ($this->orders->canTransition($order, OrderStateMachine::AWAITING_PAYMENT)) {
                $this->orders->markAwaitingPayment($order);
            }

            return $payment->load(['transactions']);
        });
    }

    public function findPayment(int $paymentId, ?int $organizationId = null): ?Payment
    {
        return $this->repository->find($paymentId, $organizationId);
    }

    public function findByPublicId(string $publicId, ?int $organizationId = null): ?Payment
    {
        return $this->repository->findByPublicId($publicId, $organizationId);
    }

    public function processWebhook(string $provider, array $payload): Payment
    {
        return DB::transaction(function () use ($provider, $payload) {
            $paymentId = (string) ($payload['object']['id']
                ?? $payload['payment_id']
                ?? $payload['id']
                ?? '');

            if ($paymentId === '') {
                throw new DomainRuleViolation(
                    'Webhook payload carries no payment id.',
                    'INVALID_WEBHOOK_PAYLOAD',
                    ['provider' => $provider],
                    422,
                );
            }

            // Find payment by provider payment ID (шлюз шлёт его в object.id)
            $payment = Payment::where('provider', $provider)
                ->where('provider_payment_id', $paymentId)
                ->first();

            if (!$payment) {
                throw new DomainRuleViolation(
                    'Unknown payment for webhook',
                    'UNKNOWN_PAYMENT',
                    ['provider' => $provider, 'payment_id' => $paymentId],
                    404,
                );
            }

            $eventType = (string) ($payload['event'] ?? $payload['event_type'] ?? '');
            $eventId = (string) ($payload['event_id'] ?? ($paymentId . ':' . $eventType));

            if ($eventId === '') {
                throw new \RuntimeException('Webhook payload carries no event_id, so it cannot be deduplicated');
            }

            // Idempotency belongs to the database, not to this method. The schema
            // already provides it: `webhook_events` carries
            // UNIQUE (provider, provider_event_id). Claiming the event with an
            // insert is atomic, so a replayed delivery loses the race and stops
            // here. The previous implementation appended the event id to a JSON
            // column on `payments` -- a read-modify-write that two concurrent
            // replays can both win, which is the opposite of what it was for.
            $claimed = DB::table('webhook_events')->insertOrIgnore([
                'provider' => $provider,
                'provider_event_id' => $eventId,
                'event_name' => $eventType,
                'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
                'processed_at' => null,
                'created_at' => now(),
            ]);

            if ($claimed === 0) {
                return $payment; // Already processed this event.
            }

            switch ($eventType) {
                case 'payment.succeeded':
                    $this->handlePaymentSucceeded($payment, $payload);
                    break;

                case 'payment.failed':
                    $this->handlePaymentFailed($payment, $payload);
                    break;

                case 'payment.canceled':
                    $this->handlePaymentCanceled($payment, $payload);
                    break;

                default:
                    // Throwing rolls the claim back with the rest of the
                    // transaction, so an event we cannot handle is not recorded
                    // as processed and can be retried once the handler exists.
                    throw new \RuntimeException('Unknown webhook event type');
            }

            DB::table('webhook_events')
                ->where('provider', $provider)
                ->where('provider_event_id', $eventId)
                ->update(['processed_at' => now()]);

            return $payment->fresh();
        });
    }

    protected function handlePaymentSucceeded(Payment $payment, array $payload): void
    {
        if (!$this->machine->can($payment->status, PaymentStateMachine::SUCCEEDED)) {
            return;
        }

        // CRITICAL: Check if associated holds are still convertible
        // This prevents race condition where hold expires during payment processing
        if ($payment->order) {
            $holdsStillValid = $this->validateHoldsForOrder($payment->order);

            if (!$holdsStillValid) {
                Log::warning('PaymentService: Holds expired during payment processing', [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order->id,
                    'provider_payment_id' => $payment->provider_payment_id,
                ]);

                // Reject payment - holds have expired. Возвращаем структурированную
                // 409 (SEAT_HOLDS_EXPIRED) вместо «голого» RuntimeException, который
                // долетал до клиента как 500: транзакция вебхука откатывается, заказ
                // не переходит в paid, места вернутся sweep-путём.
                throw new \Nabilet\Core\Errors\ConflictError(
                    'Seat holds have expired. Payment cannot be completed.',
                    'SEAT_HOLDS_EXPIRED'
                );
            }
        }

        $this->repository->markAsSucceeded($payment);

        // Add success transaction
        $this->repository->addTransaction($payment, [
            'type' => 'capture',
            'amount' => $payment->amount,
            'status' => 'succeeded',
            'payload_json' => $payload,
        ]);

        // Notify order service: full payment -> paid.
        if ($payment->order) {
            $this->orders->applyPayment($payment->order, $payment);

            // Mark holds as converted after successful order completion
            $this->markHoldsAsConverted($payment->order);

            // A6/A9: 'sold' ставится ТОЛЬКО здесь — на подтверждённой оплате
            // (InventoryItemStateMachine: held → sold happens on payment
            // confirmation). До этого момента места оставались held и при
            // неоплате возвращались в продажу sweep-путём.
            $freshOrder = $payment->order->fresh();
            if ($freshOrder !== null && $freshOrder->status === 'paid') {
                $this->markInventorySoldForOrder($freshOrder);

                // A6: выпуск билетов сразу после перехода заказа в paid — внутри той же
                // транзакции вебхука (идемпотентно: повторный вызов возвращает уже
                // выпущенные билеты). Это чинит разрыв цепочки «оплата → билет».
                //
                // Проверки на null здесь больше нет намеренно: `$ticketService`
                // объявлен обязательной зависимостью, и контейнер её разрешает
                // (см. докблок конструктора). Прежнее `if ($this->ticketService !== null)`
                // было всегда ложно и маскировало то, что билеты не выпускаются.
                $this->ticketService->issueTicketsForOrder($freshOrder);
            }
        }
    }

    /**
     * Подтверждённая продажа: места из позиций заказа переходят в 'sold'.
     */
    private function markInventorySoldForOrder(Order $order): void
    {
        foreach ($order->items()->with('inventoryItem')->get() as $item) {
            $inv = $item->inventoryItem;

            if ($inv !== null && $inv->status !== 'sold') {
                $inv->update(['status' => 'sold']);
            }
        }
    }

    /**
     * Validate that all holds for an order are still convertible.
     * Uses SeatHoldLifecycle::isHoldConvertible() — точечную проверку одного
     * холда, а не пакетный `HoldSweeper`.
     *
     * A13 (мертвая ветка): раньше брался $order->items()->first()?->cart_id —
     * у order_items колонки cart_id нет, поэтому валидация молча скатывалась в
     * fallback и не находила холдов (их вообще никто не создавал). Теперь:
     *  - источник истины — orders.cart_id (миграция 2026_09_29);
     *  - если по корзине есть активные seat_holds — проверяем каждый;
     *  - если холдов нет вовсе (легаси-заказы) — фолбэк на carts.expires_at +
     *    grace-окно (`HoldGrace`), чтобы не отклонять платежи, созданные до
     *    введения материализованных холдов.
     */
    protected function validateHoldsForOrder(Order $order): bool
    {
        $cartId = $order->cart_id;

        if (!$cartId) {
            return true; // Заказ без корзины (admin API) — холдов не было.
        }

        $holds = SeatHold::where('cart_id', $cartId)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->get();

        foreach ($holds as $hold) {
            if (!$this->holdLifecycle->isHoldConvertible($hold->id)) {
                return false;
            }
        }

        if ($holds->isNotEmpty()) {
            return true;
        }

        // Фолбэк для заказов, оформленных до появления seat_holds: TTL корзины.
        $cartExpiresAt = DB::table('carts')->where('id', $cartId)->value('expires_at');

        if ($cartExpiresAt === null) {
            return true;
        }

        $now = \Carbon\CarbonImmutable::now();

        // То же grace-окно, что и в sweeper'е/вебхуке — из общего класса
        // `HoldGrace`, а не литералом и не через повторную арифметику: иначе
        // «оплата в последнюю секунду» проходит здесь, но место уже освобождено
        // sweeper'ом.
        return HoldGrace::isWithinGrace(\Carbon\CarbonImmutable::parse($cartExpiresAt), $now);
    }

    /**
     * Mark all holds for an order as converted after successful payment.
     */
    protected function markHoldsAsConverted(Order $order): void
    {
        $cartId = $order->cart_id;

        if (!$cartId) {
            return;
        }

        $holds = SeatHold::where('cart_id', $cartId)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->get();

        foreach ($holds as $hold) {
            $this->holdLifecycle->markAsConverted($hold->id);
        }
    }

    protected function handlePaymentFailed(Payment $payment, array $payload): void
    {
        if (!$this->machine->can($payment->status, PaymentStateMachine::FAILED)) {
            return;
        }

        $cancellation = is_array($payload['cancellation_details'] ?? null)
            ? $payload['cancellation_details']
            : (is_array($payload['object']['cancellation_details'] ?? null) ? $payload['object']['cancellation_details'] : []);

        $this->repository->markAsFailed(
            $payment,
            (string) ($cancellation['reason'] ?? ($payload['failure_code'] ?? '')),
            (string) ($cancellation['party'] ?? ($payload['failure_message'] ?? '')),
        );

        // Add failed transaction
        $this->repository->addTransaction($payment, [
            'type' => 'failure',
            'amount' => 0,
            'status' => 'failed',
            'payload_json' => $payload,
        ]);

        // A12: отказ платежа обязан двигать заказ — иначе заказ вечно висит в
        // awaiting_payment, а места остаются удержанными. Машина разрешает
        // payment_failed → awaiting_payment (ретрай другой картой), поэтому
        // повторная оплата не блокируется. Если заказ уже paid/cancelled/expired
        // (поздний failed после succeeded) — переход запрещён и молча пропускаем.
        $order = $payment->order;

        if ($order !== null && $this->orders->canTransition($order, OrderStateMachine::PAYMENT_FAILED)) {
            $this->orders->markPaymentFailed($order);

            // Вернуть места тем же sweep-путём: холды заказа ещё не converted,
            // sweeper освободит инвентарь в ближайший проход.
            $this->holdSweeper->sweep();
        }
    }

    /**
     * A12: отмена платежа провайдером (payment.canceled) — отличный от failed
     * статус. Раньше оба события шли в handlePaymentFailed и платёж помечался
     * 'failed', из-за чего отменённый платёж был неотличим от неудачного. Здесь
     * статус платежа становится PaymentStateMachine::CANCELED ('canceled'), а заказ
     * уводится в payment_failed (разрешён ретрай другой картой), места возвращаются.
     */
    protected function handlePaymentCanceled(Payment $payment, array $payload): void
    {
        if (!$this->machine->can($payment->status, PaymentStateMachine::CANCELED)) {
            return;
        }

        $payment->update(['status' => PaymentStateMachine::CANCELED]);

        $order = $payment->order;

        if ($order !== null && $this->orders->canTransition($order, OrderStateMachine::PAYMENT_FAILED)) {
            $this->orders->markPaymentFailed($order);

            // Холды заказа ещё не converted — sweep освободит инвентарь.
            $this->holdSweeper->sweep();
        }
    }

    /**
     * Возврат средств — делегат в RefundService.
     *
     * Публичная сигнатура сохранена ради существующих вызывающих мест; вся
     * доменная логика (валидация остатка, машина состояний возврата, запрос к
     * провайдеру через реестр) живёт в RefundService::refund().
     */
    public function refundPayment(Payment $payment, ?int $amount = null, ?string $reason = null): Payment
    {
        return $this->refunds->refund($payment, $amount, $reason);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPaymentsByOrder(int $orderId): array
    {
        return $this->repository->findByOrder($orderId)->toArray();
    }

    /**
     * Постраничный список платежей.
     *
     * FAIL-CLOSED: без скоупа — по организации или по пользователю — метод
     * возвращает пустой результат, а не все платежи. Это осознанная гарантия
     * (её проверяет `PaymentWritePathTest::test_payment_index_is_fail_closed_without_a_tenant_context`):
     * у `payments` нет колонки `organization_id`, скоуп идёт через заказ, и
     * забытый фильтр означал бы выдачу платежей всех арендаторов.
     * Скоуп задаёт контроллер: обычному пользователю — свой `user_id`,
     * сотруднику — организация из `user_organization`.
     *
     * Раньше метод возвращал пустой результат ВСЕГДА, потому что контроллер не
     * пропускал `organization_id` (его не было в `only()`), а ветка с реальным
     * запросом обращалась к `$this->repository->model` — `protected`-свойству,
     * то есть была фаталом «Cannot access protected property». Построение
     * запроса перенесено в `PaymentRepository::paginate()`.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        if (empty($filters['organization_id']) && empty($filters['user_id'])) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        return $this->repository->paginate($filters, $perPage);
    }
}
