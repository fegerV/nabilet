<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Services;

use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\StateMachine\StateMachine;
use Nabilet\Core\Support\HoldGrace;
use Nabilet\Core\Support\Money;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\SeatHold;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Domain\PaymentSettlement;
use Nabilet\Modules\Payments\Domain\SettlementDecision;
use Nabilet\Modules\Payments\Domain\SettlementRequest;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Models\Refund;
use Nabilet\Modules\Payments\Repositories\PaymentRepository;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Payments\StateMachines\RefundStateMachine;
use Nabilet\Modules\Inventory\Services\HoldSweeper;
use Nabilet\Modules\Inventory\Services\SeatHoldLifecycle;
use Nabilet\Modules\Inventory\StateMachines\InventoryItemStateMachine;
use Nabilet\Modules\Tickets\Models\Ticket;
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
     * Возвращает payment + confirmation_url (редирект на шлюз или demo-pay)
     * и признак `created`: `false` — платёж переиспользован (повторный запрос),
     * `true` — создан этим вызовом. Контроллер по нему выбирает 201 или 200.
     *
     * Ключ идемпотентности ОБЯЗАТЕЛЕН (S-07). Без него двойной клик по кнопке
     * «Оплатить» или ретрай уровня сети создавал второй pending-платёж по тому
     * же заказу: сверка с провайдером потом видела два зачисления против одной
     * оплаты, а возврат мог уйти дважды. Проверка стоит здесь, а не только в
     * контроллере, потому что этот метод — единственная точка, которая реально
     * дедуплицирует: вызов мимо валидации HTTP (админка, скрипты, embed)
     * не должен уметь создать платёж без ключа.
     *
     * @param  array{idempotency_key?: string|null}  $data
     * @return array{payment: Payment, confirmation_url: string|null, created: bool}
     */
    public function initiatePayment(int $orderId, array $data = []): array
    {
        $providerName = PaymentProviderRegistry::defaultProviderName();
        $idempotencyKey = $this->normalizeIdempotencyKey($data['idempotency_key'] ?? null);

        // ФАЗА 1 — только БД, без сети.
        //
        // `lockForUpdate()` на строке заказа сериализует параллельные запросы:
        // второй ждёт коммита первого и видит созданный им платёж. Без блокировки
        // два одновременных `POST /payments` с РАЗНЫМИ ключами оба проходили
        // проверку «активного платежа нет» и создавали два pending-платежа на
        // один заказ — двойное списание, если покупатель успевал оплатить оба
        // (аудит R-02). UNIQUE на ключе идемпотентности здесь не спасает: ключи
        // разные.
        $replay = DB::transaction(function () use ($orderId, $providerName, $idempotencyKey) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            // Идемпотентный повтор проверяется ДО проверки оплачиваемости: клиент,
            // повторивший запрос с тем же ключом по уже оплаченному заказу, должен
            // получить свой платёж (200), а не 409 «заказ нельзя оплатить».
            $reusable = $this->reusablePayment($orderId, $providerName, $idempotencyKey);

            if ($reusable !== null) {
                return $reusable;
            }

            // Оплатить можно только «живой» заказ (аудит R-05).
            $this->assertOrderIsPayable($order);

            return null;
        });

        if ($replay !== null) {
            return $replay;
        }

        // ФАЗА 2 — вызов провайдера ВНЕ транзакции (аудит R-09).
        //
        // Раньше `createPayment()` провайдера вызывался внутри
        // `DB::transaction()`: при сетевом таймауте ЮKassa платёж создавала, а
        // наша транзакция откатывалась — у провайдера платёж есть, у нас строки
        // нет, и восстановить связь нечем. Плюс блокировка строки заказа из фазы
        // 1 держалась бы всё время сетевого вызова, а это исчерпание пула
        // соединений под нагрузкой.
        //
        // От потери при таймауте защищает ключ идемпотентности: он стабилен между
        // ретраями, поэтому провайдер вернёт тот же платёж, а не создаст второй.
        $order = Order::findOrFail($orderId);

        $providerResult = $this->providers->make($providerName)->createPayment([
            'order_id' => (string) $orderId,
            'amount' => (int) $order->total_amount,
            'currency' => $order->currency ?? 'RUB',
            'description' => "Order #{$orderId}",
            'success_url' => (string) config('nabilet.payment.yookassa.return_url', config('app.url')),
            'idempotency_key' => $idempotencyKey,
        ]);

        // ФАЗА 3 — запись, с повторной блокировкой и повторной проверкой.
        //
        // Между фазами 1 и 3 параллельный запрос мог успеть создать платёж; тогда
        // побеждает он, а созданный нами intent у провайдера остаётся
        // неоплаченным и истекает сам — денег он не двигает, поэтому «лишний»
        // вызов провайдера безопасен.
        return DB::transaction(function () use ($orderId, $providerName, $providerResult, $idempotencyKey) {
            // Заказ читается СВЕЖИМ под блокировкой, а не берётся из фазы 2:
            // `$order` — это снимок ДО сетевого вызова, и его статус мог
            // измениться, пока мы разговаривали с провайдером (отмена, истечение
            // холда). Проверка по устаревшей модели пропускала бы именно ту
            // гонку, от которой защищает фаза 3.
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            $raced = $this->reusablePayment($orderId, $providerName, $idempotencyKey);

            if ($raced !== null) {
                return $raced;
            }

            $this->assertOrderIsPayable($order);

            $payment = $this->createPayment($orderId, [
                'provider' => $providerName,
                'provider_payment_id' => $providerResult['payment_id'],
                'amount' => (int) $order->total_amount,
                'currency' => $order->currency ?? 'RUB',
                'payment_url' => $providerResult['confirmation_url'],
                'idempotency_key' => $idempotencyKey,
            ]);

            return [
                'payment' => $payment,
                'confirmation_url' => $providerResult['confirmation_url'],
                'created' => true,
            ];
        });
    }

    /**
     * Платёж, который можно вернуть вместо создания нового, или `null`.
     *
     * Два правила, в порядке приоритета:
     *
     *  1. Тот же ключ идемпотентности → тот же платёж (повтор запроса). Ветка
     *     идёт ПЕРВОЙ: завершённый платёж (`succeeded`/`canceled`) не попадает
     *     под «активный», и без этой ветки повтор создавал бы вторую строку с тем
     *     же ключом → 500 на UNIQUE `uq_payments_idempotency`.
     *  2. Активный платёж по заказу → он же. Ретрай с ДРУГИМ ключом тоже не
     *     должен плодить pending-платежи.
     *
     * Вызывается внутри транзакции, уже держащей блокировку строки заказа.
     *
     * @return array{payment: Payment, confirmation_url: string|null, created: bool}|null
     */
    private function reusablePayment(int $orderId, string $providerName, string $idempotencyKey): ?array
    {
        $byKey = Payment::where('provider', $providerName)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($byKey !== null) {
            // Ключ уже использован для ДРУГОГО заказа — ошибка клиента
            // (переиспользование ключа), а не «верни чужой платёж»: молчаливая
            // выдача payment другого заказа отправляла покупателя платить не за
            // тот заказ. 409 IDEMPOTENCY_CONFLICT.
            if ((int) $byKey->order_id !== $orderId) {
                throw new ConflictError(
                    'Idempotency key was already used for another order.',
                    'IDEMPOTENCY_CONFLICT',
                    ['used_for_order_id' => (int) $byKey->order_id],
                );
            }

            return [
                'payment' => $byKey,
                'confirmation_url' => $byKey->payment_url,
                'created' => false,
            ];
        }

        $existing = Payment::where('order_id', $orderId)
            ->whereIn('status', [PaymentStateMachine::PENDING, PaymentStateMachine::WAITING_FOR_CAPTURE])
            ->first();

        if ($existing !== null) {
            return [
                'payment' => $existing,
                'confirmation_url' => $existing->payment_url,
                'created' => false,
            ];
        }

        return null;
    }

    /**
     * Заказ обязан допускать оплату (аудит R-05).
     *
     * Раньше статус заказа при инициации не проверялся вообще: отменённый,
     * просроченный, уже возвращённый или уже оплаченный заказ всё равно получал
     * платёжную ссылку. Покупатель платил по отменённому заказу, вебхук затем
     * уходил в автовозврат — деньги возвращались, но с задержкой и с мусором в
     * сверке; а по уже оплаченному заказу новая ссылка означала второе списание.
     *
     * `payment_failed` разрешён НАМЕРЕННО: покупатель с отклонённой картой должен
     * иметь возможность заплатить снова (см. OrderStateMachine — `failed` не
     * терминальный именно для этого).
     */
    private function assertOrderIsPayable(Order $order): void
    {
        $payable = [
            OrderStateMachine::PENDING,
            OrderStateMachine::AWAITING_PAYMENT,
            OrderStateMachine::PAYMENT_FAILED,
        ];

        if (! in_array((string) $order->status, $payable, true)) {
            throw new ConflictError(
                'This order cannot be paid.',
                'ORDER_NOT_PAYABLE',
                [
                    'order_id' => (int) $order->id,
                    'status' => (string) $order->status,
                ],
            );
        }
    }

    /**
     * Привести ключ идемпотентности к каноническому виду или отказать.
     *
     * Пустое значение — не «нет ключа», а невалидный запрос: клиент обязан
     * прислать ключ (S-07). Длина ограничена 255 — столько же, сколько в
     * колонке `payments.idempotency_key`; длиннее MySQL молча обрежет, и два
     * разных ключа с общим префиксом схлопнулись бы в один.
     */
    private function normalizeIdempotencyKey(?string $key): string
    {
        $key = trim((string) $key);

        if ($key === '') {
            throw new ValidationError([
                'idempotency_key' => ['The idempotency key field is required.'],
            ]);
        }

        if (strlen($key) > 255) {
            throw new ValidationError([
                'idempotency_key' => ['The idempotency key must not be greater than 255 characters.'],
            ]);
        }

        return $key;
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
                // Сумма обязательна: вебхук сверяет её с заказом (PaymentSettlement).
                // Без неё демо-подтверждение выглядело бы недоплатой и уходило бы
                // в автовозврат вместо выпуска билета.
                'amount' => [
                    'value' => number_format((int) $payment->amount / 100, 2, '.', ''),
                    'currency' => (string) ($payment->currency ?? 'RUB'),
                ],
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
            $eventType = (string) ($payload['event'] ?? $payload['event_type'] ?? '');

            $paymentId = $this->resolveWebhookPaymentId($eventType, $payload);

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

                // Возвраты асинхронны: провайдер принимает запрос, а исход
                // сообщает уведомлением. Без этих двух ветвей каждое штатное
                // уведомление ЮKassa о возврате падало в `default` →
                // RuntimeException → 500, claim откатывался, провайдер повторял
                // его бесконечно, а возврат навсегда оставался `processing`
                // (`completed_at` не проставлялся).
                case 'refund.succeeded':
                case 'refund.failed':
                    $this->handleRefundEvent($eventType, $payment, $payload);
                    break;

                // waiting_for_capture — не терминал и не сбой (R-14): при
                // capture:true (наш режим) состояние почти недостижимо, но
                // включение двухшаговой схемы не должно превращать каждое
                // штатное уведомление в 500 с бесконечным ретраем провайдера.
                // Заявляем событие обработанным, состояние платежа не трогаем:
                // платёж уже pending, деньги ещё не зачислены — записывать
                // capture-транзакцию рано.
                case 'payment.waiting_for_capture':
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
        $order = $payment->order;

        // Решение принимает доменный PaymentSettlement: сверка суммы и валюты,
        // запрет воскрешения терминального заказа, идемпотентный replay.
        // Раньше вебхук не читал из payload ни сумму, ни валюту — заказ
        // становился `paid`, а билет выпускался по ЛЮБОЙ сумме, включая ноль.
        $decision = $this->settleSucceeded($payment, $payload);

        if ($decision->isReplay()) {
            // Уже в целевом состоянии: провайдеру нужен ответ «ок», писать нечего.
            return;
        }

        if ($decision->isRefused()) {
            $this->recordReceiptAndRefund($payment, $payload, (string) $decision->reason());

            return;
        }

        // CRITICAL: холд истёк, пока деньги были в пути — места уже перепроданы,
        // выпускать билет не из чего. Раньше здесь бросался ConflictError: он
        // откатывал claim события, провайдер повторял уведомление бесконечно, а
        // деньги оставались списанными без билета и без возврата. Теперь это
        // терминальный исход — фиксируем поступление и возвращаем деньги.
        if ($order !== null && ! $this->validateHoldsForOrder($order)) {
            Log::warning('PaymentService: Holds expired during payment processing', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'provider_payment_id' => $payment->provider_payment_id,
            ]);

            $this->recordReceiptAndRefund($payment, $payload, 'seat_holds_expired');

            return;
        }

        $this->repository->markAsSucceeded($payment);

        // Add success transaction
        $this->repository->addTransaction($payment, [
            'type' => 'capture',
            'amount' => $payment->amount,
            'status' => 'succeeded',
            'payload_json' => $payload,
            'provider_event_id' => $this->providerEventId($payment, $payload),
        ]);

        // Notify order service: full payment -> paid.
        if ($order !== null) {
            $this->orders->applyPayment($order, $payment);

            // Mark holds as converted after successful order completion
            $this->markHoldsAsConverted($order);

            // A6/A9: 'sold' ставится ТОЛЬКО здесь — на подтверждённой оплате
            // (InventoryItemStateMachine: held → sold happens on payment
            // confirmation). До этого момента места оставались held и при
            // неоплате возвращались в продажу sweep-путём.
            $freshOrder = $order->fresh();
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
     * Что разрешено сделать уведомлению `payment.succeeded`.
     *
     * Готовый доменный слой `PaymentSettlement`/`SettlementRequest` существовал и
     * был покрыт unit-тестами, но к пути вебхука подключён НЕ был (ни одного
     * вызова вне `Domain/`). Именно поэтому подделанное или переупорядоченное
     * уведомление с любой суммой оплачивало заказ.
     */
    private function settleSucceeded(Payment $payment, array $payload): SettlementDecision
    {
        $order = $payment->order;

        $orderTotal = Money::of(
            (int) ($order?->total_amount ?? $payment->amount),
            (string) ($order?->currency ?? $payment->currency ?? 'RUB'),
        );

        $reported = $this->reportedAmount($payment, $payload);

        if ($reported === null) {
            // Суммы в уведомлении нет — сверять нечего. Fail-closed: отказ, а не
            // молчаливая выдача билета.
            return SettlementDecision::refused(PaymentSettlement::REASON_AMOUNT_MISMATCH);
        }

        try {
            $request = new SettlementRequest(
                orderStatus: (string) ($order?->status ?? OrderStateMachine::PENDING),
                paymentStatus: (string) $payment->status,
                orderTotal: $orderTotal,
                reportedAmount: $reported,
                providerEvent: PaymentStateMachine::SUCCEEDED,
                // Вторая и третья доставка того же уведомления не должны выпустить
                // второй комплект билетов — оценщик обязан знать, что сайд-эффект
                // уже произошёл.
                ticketsAlreadyIssued: $order !== null
                    && Ticket::where('order_id', $order->id)->exists(),
            );
        } catch (DomainRuleViolation $e) {
            // Валюта заказа и валюта уведомления разошлись — конструктор
            // `SettlementRequest` это отвергает (CURRENCY_MISMATCH). Тоже
            // терминальный отказ, а не повод для вечного ретрая.
            return SettlementDecision::refused($e->errorCode);
        }

        return (new PaymentSettlement())->settle($request);
    }

    /**
     * Сумма из уведомления в минорных единицах.
     *
     * ЮKassa шлёт `object.amount = {value: "5000.00", currency: "RUB"}`.
     * `Money::fromDecimal` разбирает десятичную строку строковой арифметикой —
     * float здесь недопустим (см. `Money`). `null` означает «суммы нет»,
     * и вызывающий обязан трактовать это как отказ, а не как ноль.
     */
    private function reportedAmount(Payment $payment, array $payload): ?Money
    {
        $object = is_array($payload['object'] ?? null) ? $payload['object'] : $payload;
        $amount = $object['amount'] ?? null;

        $value = is_array($amount) ? ($amount['value'] ?? null) : $amount;

        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        $currency = is_array($amount) ? ($amount['currency'] ?? null) : null;

        if (! is_string($currency) || $currency === '') {
            $currency = $object['currency'] ?? $payment->currency ?? 'RUB';
        }

        try {
            return Money::fromDecimal((string) $value, (string) $currency);
        } catch (DomainRuleViolation) {
            return null;
        }
    }

    /**
     * Стабильный идентификатор события провайдера для журнала транзакций.
     *
     * `payment_transactions.provider_event_id` и UNIQUE (payment_id,
     * provider_event_id) существуют в схеме, но никогда не заполнялись — защита
     * от дублей записей журнала была объявлена и мертва (в MySQL NULL не
     * конфликтует с NULL).
     */
    private function providerEventId(Payment $payment, array $payload): string
    {
        $explicit = (string) ($payload['event_id'] ?? '');

        if ($explicit !== '') {
            return $explicit;
        }

        $type = (string) ($payload['event'] ?? $payload['event_type'] ?? 'event');
        $objectId = (string) ($payload['object']['id'] ?? $payment->provider_payment_id ?? $payment->id);

        return $objectId . ':' . $type;
    }

    /**
     * Деньги у провайдера взяты, но заказ их принять не может.
     *
     * Терминальный исход вместо исключения: claim уведомления НЕ откатывается
     * (иначе провайдер повторял бы доставку вечно), поступление фиксируется в
     * журнале — наши книги обязаны сходиться с выпиской провайдера, — а деньги
     * сразу возвращаются тем же путём, что и ручной возврат (`RefundService`:
     * RefundLedger выводит статус заказа из денег).
     */
    private function recordReceiptAndRefund(Payment $payment, array $payload, string $reason): void
    {
        Log::critical('PaymentService: settled payment cannot be applied to the order — refunding', [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'order_status' => $payment->order?->status,
            'provider_payment_id' => $payment->provider_payment_id,
            'reason' => $reason,
        ]);

        if ($payment->status !== PaymentStateMachine::SUCCEEDED) {
            $this->repository->markAsSucceeded($payment);
        }

        $this->repository->addTransaction($payment, [
            'type' => 'capture',
            'amount' => $payment->amount,
            'status' => 'succeeded',
            'payload_json' => $payload + ['settlement_refusal' => $reason],
            'provider_event_id' => $this->providerEventId($payment, $payload),
        ]);

        try {
            $this->refunds->refund($payment->fresh(), null, 'Автовозврат: ' . $reason);
        } catch (\Throwable $e) {
            // Провайдер недоступен или возврат уже оформлен — запись в `refunds`
            // (requested/failed) остаётся видимой оператору, деньги не теряются.
            Log::error('PaymentService: automatic refund after a refused settlement failed', [
                'payment_id' => $payment->id,
                'reason' => $reason,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Исход возврата от провайдера (`refund.succeeded` / `refund.failed`).
     *
     * Машина: `processing → succeeded | failed`. Успех обязан проставить
     * `completed_at` — без этого возврат вечно висел в `processing`, а
     * `RefundService` считал такие зависшие возвраты зачтёнными.
     */
    protected function handleRefundEvent(string $eventType, Payment $payment, array $payload): void
    {
        $object = is_array($payload['object'] ?? null) ? $payload['object'] : [];
        $providerRefundId = (string) ($object['id'] ?? '');

        $refund = $providerRefundId !== ''
            ? Refund::where('payment_id', $payment->id)
                ->where('provider_refund_id', $providerRefundId)
                ->first()
            : null;

        // Фолбэк: id возврата в уведомлении отсутствует — берём последний
        // незакрытый возврат этого платежа.
        $refund ??= Refund::where('payment_id', $payment->id)
            ->whereIn('status', [RefundStateMachine::REQUESTED, RefundStateMachine::PROCESSING])
            ->latest('id')
            ->first();

        if ($refund === null) {
            Log::warning('PaymentService: refund webhook for an unknown refund', [
                'payment_id' => $payment->id,
                'provider_refund_id' => $providerRefundId,
                'event' => $eventType,
            ]);

            return;
        }

        $target = $eventType === 'refund.succeeded'
            ? RefundStateMachine::SUCCEEDED
            : RefundStateMachine::FAILED;

        if ($refund->status === $target) {
            return; // Повторная доставка — идемпотентно.
        }

        if (! RefundStateMachine::make()->can((string) $refund->status, $target)) {
            Log::warning('PaymentService: illegal refund transition', [
                'refund_id' => $refund->id,
                'from' => $refund->status,
                'to' => $target,
            ]);

            return;
        }

        $refund->update([
            'status' => $target,
            'completed_at' => $target === RefundStateMachine::SUCCEEDED ? now() : null,
        ]);

        $this->repository->addTransaction($payment, [
            'type' => 'refund',
            'amount' => -((int) $refund->amount),
            'status' => $target === RefundStateMachine::SUCCEEDED ? 'succeeded' : 'failed',
            'payload_json' => $payload,
            'provider_event_id' => $this->providerEventId($payment, $payload),
        ]);
    }

    /**
     * Идентификатор платежа, к которому относится уведомление.
     *
     * Уведомление о возврате описывает ОБЪЕКТ ВОЗВРАТА: его `object.id` — это id
     * возврата, а платёж лежит в `object.payment_id`. Поиск платежа по
     * `object.id` давал 404 на каждое штатное уведомление ЮKassa о возврате, а
     * 404 откатывает claim — провайдер повторял бы его бесконечно.
     */
    private function resolveWebhookPaymentId(string $eventType, array $payload): string
    {
        $object = is_array($payload['object'] ?? null) ? $payload['object'] : [];

        if (str_starts_with($eventType, 'refund.')) {
            return (string) ($object['payment_id'] ?? '');
        }

        return (string) ($object['id']
            ?? $payload['payment_id']
            ?? $payload['id']
            ?? '');
    }

    /**
     * Подтверждённая продажа: места из позиций заказа переходят в 'sold'.
     *
     * Переход проверяется по `InventoryItemStateMachine`, а не пишется напрямую
     * (аудит R-12). Раньше здесь стоял безусловный
     * `update(['status' => 'sold'])`: место в статусе `disabled` (выключено для
     * этого сеанса) или `blocked` (служебное) тоже становилось `sold`, то есть в
     * зале появлялось проданное место, которого в продаже не существовало.
     * Машина такие переходы запрещает, и запрет обязан соблюдаться здесь.
     */
    private function markInventorySoldForOrder(Order $order): void
    {
        $machine = InventoryItemStateMachine::make();

        foreach ($order->items()->with('inventoryItem')->get() as $item) {
            $inv = $item->inventoryItem;

            if ($inv === null || $inv->status === InventoryItemStateMachine::SOLD) {
                continue;
            }

            if (! $machine->can((string) $inv->status, InventoryItemStateMachine::SOLD)) {
                Log::warning('PaymentService: inventory item cannot be marked sold', [
                    'inventory_item_id' => $inv->id,
                    'order_id' => $order->id,
                    'status' => $inv->status,
                ]);

                continue;
            }

            $inv->update(['status' => InventoryItemStateMachine::SOLD]);
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

        // lockForUpdate при выборке: без него sweeper мог бы отпустить холд
        // между этим чтением и isHoldConvertible() (аудит D-5/S6, миллисекундное
        // окно grace). Строка холда перечитывается с блокировкой внутри
        // isHoldConvertible(), поэтому двойная блокировка здесь безопасна.
        $holds = SeatHold::where('cart_id', $cartId)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->lockForUpdate()
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
            'provider_event_id' => $this->providerEventId($payment, $payload),
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

        // Переход проверен машиной выше; сама запись — через репозиторий, чтобы
        // статус платежа менялся в одном месте (аудит R-12).
        $this->repository->markAsCanceled($payment);

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
