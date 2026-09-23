<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Services;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\SeatHold;
use Nabilet\Modules\Orders\Services\OrderService;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Models\Refund;
use Nabilet\Modules\Payments\Providers\YooKassaProvider;
use Nabilet\Modules\Payments\Repositories\PaymentRepository;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Payments\StateMachines\RefundStateMachine;
use Nabilet\Modules\Inventory\Services\HoldSweeper;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    private \Nabilet\Core\StateMachine\StateMachine $machine;

    public function __construct(
        protected PaymentRepository $repository,
        protected HoldSweeper $holdSweeper,
        protected OrderService $orders,
    ) {
        $this->machine = PaymentStateMachine::make();
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
                'provider' => $data['provider'] ?? 'yookassa',
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

    public function findPayment(int $paymentId, int $organizationId = null): ?Payment
    {
        return $this->repository->find($paymentId, $organizationId);
    }

    public function findByPublicId(string $publicId, int $organizationId = null): ?Payment
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

                // Find payment by provider payment ID (YooKassa шлёт его в object.id)
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

                // Process based on event type
                switch ($eventType) {
                    case 'payment.succeeded':
                        $this->handlePaymentSucceeded($payment, $payload);
                        break;

                    case 'payment.failed':
                    case 'payment.canceled':
                        $this->handlePaymentFailed($payment, $payload);
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

                // Reject payment - holds have expired
                throw new \RuntimeException('Seat holds have expired. Payment cannot be completed.');
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
        }
    }

    /**
     * Validate that all holds for an order are still convertible.
     * Uses HoldSweeper's isHoldConvertible() method with proper locking.
     */
    protected function validateHoldsForOrder(Order $order): bool
    {
        // Find all active holds for this order's cart
        $cartId = $order->items()->first()?->cart_id;

        if (!$cartId) {
            // No cart association, check if order has items with inventory
            $inventoryItemIds = $order->items()->pluck('inventory_item_id')->toArray();

            if (empty($inventoryItemIds)) {
                return true; // No inventory items to validate
            }

            // Check for any active holds on these inventory items
            $holds = SeatHold::whereIn('inventory_item_id', $inventoryItemIds)
                ->whereNull('converted_at')
                ->whereNull('released_at')
                ->get();

            foreach ($holds as $hold) {
                if (!$this->holdSweeper->isHoldConvertible($hold->id)) {
                    return false;
                }
            }

            return true;
        }

        // Check holds by cart_id
        $holds = SeatHold::where('cart_id', $cartId)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->get();

        foreach ($holds as $hold) {
            if (!$this->holdSweeper->isHoldConvertible($hold->id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mark all holds for an order as converted after successful payment.
     */
    protected function markHoldsAsConverted(Order $order): void
    {
        $cartId = $order->items()->first()?->cart_id;

        if (!$cartId) {
            return;
        }

        $holds = SeatHold::where('cart_id', $cartId)
            ->whereNull('converted_at')
            ->whereNull('released_at')
            ->get();

        foreach ($holds as $hold) {
            $this->holdSweeper->markAsConverted($hold->id);
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
            (string) ($cancellation['reason'] ?? ($payload['failure_code'] ?? null)),
            (string) ($cancellation['party'] ?? ($payload['failure_message'] ?? null)),
        );

        // Add failed transaction
        $this->repository->addTransaction($payment, [
            'type' => 'failure',
            'amount' => 0,
            'status' => 'failed',
            'payload_json' => $payload,
        ]);
    }

    public function refundPayment(Payment $payment, int $amount = null, string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $amount, $reason) {
            if ($payment->status !== PaymentStateMachine::SUCCEEDED) {
                throw new DomainRuleViolation(
                    'Can only refund succeeded payments',
                    'PAYMENT_NOT_SUCCEEDED',
                    ['payment_id' => $payment->id, 'status' => $payment->status],
                    422,
                );
            }

            // Prevent duplicate refunds
            $totalRefunded = (int) $payment->refunds()->where('status', '!=', 'failed')->sum('amount');
            if ($totalRefunded >= $payment->amount) {
                throw new DomainRuleViolation(
                    'Payment already fully refunded',
                    'PAYMENT_ALREADY_REFUNDED',
                    ['payment_id' => $payment->id],
                    422,
                );
            }

            $refundAmount = $amount ?? ($payment->amount - $totalRefunded);

            // Validate refund amount
            if ($totalRefunded + $refundAmount > $payment->amount) {
                throw new DomainRuleViolation(
                    'Refund amount exceeds remaining payment balance',
                    'REFUND_EXCEEDS_BALANCE',
                    ['payment_id' => $payment->id, 'amount' => $refundAmount],
                    422,
                );
            }

            // Create refund record — order_id NOT NULL в схеме.
            $refund = $payment->refunds()->create([
                'order_id' => $payment->order_id,
                'amount' => $refundAmount,
                'currency' => $payment->currency,
                'reason' => $reason,
                'status' => RefundStateMachine::PROCESSING,
                'provider_refund_id' => null,
            ]);

            // Process refund through provider based on payment provider type
            try {
                $providerName = $payment->provider ?? 'yookassa';

                // Get appropriate provider instance
                $provider = match (strtolower($providerName)) {
                    'yookassa' => app(YooKassaProvider::class),
                    default => $this->getProvider($providerName),
                };

                if ($provider === null) {
                    throw new \RuntimeException("Payment provider '{$providerName}' not found");
                }

                // Call provider-specific refund method
                $providerResponse = $provider->refund([
                    'payment_id' => $payment->provider_payment_id,
                    'amount' => $refundAmount,
                    'currency' => $payment->currency,
                    'reason' => $reason,
                ]);

                // Update refund with provider response
                $refund->update([
                    'provider_refund_id' => $providerResponse['refund_id'] ?? null,
                    'status' => RefundStateMachine::PROCESSING,
                ]);

                // Add refund transaction record
                $this->repository->addTransaction($payment, [
                    'type' => 'refund',
                    'amount' => -$refundAmount,
                    'status' => 'pending',
                    'payload_json' => ['refund_id' => $refund->id, 'reason' => $reason],
                ]);

            } catch (\Exception $e) {
                // Mark refund as failed
                $refund->update([
                    'status' => RefundStateMachine::FAILED,
                ]);

                throw new \RuntimeException('Refund processing failed: ' . $e->getMessage());
            }

            return $payment->fresh();
        });
    }

    /**
     * Get payment provider instance
     */
    protected function getProvider(string $name): ?object
    {
        // Try to resolve from container first
        try {
            $className = match(strtolower($name)) {
                'yookassa' => '\\Nabilet\\Modules\\Payments\\Payments\\Providers\\YooKassaProvider',
                'stripe' => '\\Nabilet\\Modules\\Payments\\Payments\\Providers\\StripeProvider',
                'kaspi' => '\\Nabilet\\Modules\\Payments\\Payments\\Providers\\KaspiProvider',
                default => null,
            };

            if ($className && class_exists($className)) {
                return app($className);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    public function getPaymentsByOrder(int $orderId): array
    {
        return $this->repository->findByOrder($orderId)->toArray();
    }

    public function paginate(array $filters, int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        $organizationId = $filters['organization_id'] ?? null;

        if ($organizationId === null || $organizationId === '' || (int) $organizationId <= 0) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }

        return $this->repository->model
            ->newQuery()
            ->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->with(['order', 'transactions'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}