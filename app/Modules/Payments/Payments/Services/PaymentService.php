<?php

declare(strict_types=1);

namespace App\Modules\Payments\Payments\Services;

use App\Modules\Payments\Payments\Models\Payment;
use App\Modules\Payments\Payments\Repositories\PaymentRepository;
use App\Modules\Payments\Payments\Domain\PaymentStateMachine;
use App\Modules\Orders\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        protected PaymentRepository $repository,
        protected PaymentStateMachine $stateMachine
    ) {}

    public function createPayment(int $orderId, array $data): Payment
    {
        return DB::transaction(function () use ($orderId, $data) {
            $order = Order::findOrFail($orderId);

            $payment = $this->repository->create([
                'public_id' => Str::uuid()->toString(),
                'order_id' => $orderId,
                'organization_id' => $order->organization_id,
                'amount' => $data['amount'] ?? $order->total_amount,
                'currency' => $data['currency'] ?? $order->currency,
                'method' => $data['method'],
                'status' => 'pending',
                'provider' => $data['provider'] ?? null,
                'provider_payment_id' => $data['provider_payment_id'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'webhook_url' => $data['webhook_url'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            // Create initial transaction record
            $this->repository->addTransaction($payment, [
                'type' => 'authorization',
                'amount' => $payment->amount,
                'status' => 'pending',
                'metadata' => [],
            ]);

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
            // Find payment by provider payment ID
            $payment = Payment::where('provider', $provider)
                ->where('provider_payment_id', $payload['payment_id'] ?? null)
                ->firstOrFail();

            // Check idempotency - prevent duplicate processing
            if ($payment->processed_webhook_events ?? []) {
                if (in_array($payload['event_id'] ?? null, $payment->processed_webhook_events)) {
                    return $payment; // Already processed this event
                }
            }

            // Process based on event type
            switch ($payload['event_type'] ?? null) {
                case 'payment.succeeded':
                    $this->handlePaymentSucceeded($payment, $payload);
                    break;
                    
                case 'payment.failed':
                    $this->handlePaymentFailed($payment, $payload);
                    break;
                    
                default:
                    throw new \RuntimeException('Unknown webhook event type');
            }

            // Mark event as processed
            $processedEvents = $payment->processed_webhook_events ?? [];
            $processedEvents[] = $payload['event_id'] ?? null;
            $payment->update(['processed_webhook_events' => $processedEvents]);

            return $payment->fresh();
        });
    }

    protected function handlePaymentSucceeded(Payment $payment, array $payload): void
    {
        if (!$this->stateMachine->canTransition($payment, 'succeeded')) {
            return;
        }

        $this->repository->markAsSucceeded($payment);

        // Add success transaction
        $this->repository->addTransaction($payment, [
            'type' => 'capture',
            'amount' => $payment->amount,
            'status' => 'succeeded',
            'metadata' => $payload,
        ]);

        // Notify order service
        if ($payment->order) {
            $payment->order->service()->applyPayment($payment);
        }
    }

    protected function handlePaymentFailed(Payment $payment, array $payload): void
    {
        if (!$this->stateMachine->canTransition($payment, 'failed')) {
            return;
        }

        $this->repository->markAsFailed(
            $payment,
            $payload['failure_code'] ?? null,
            $payload['failure_message'] ?? null
        );

        // Add failed transaction
        $this->repository->addTransaction($payment, [
            'type' => 'failure',
            'amount' => 0,
            'status' => 'failed',
            'metadata' => $payload,
        ]);
    }

    public function refundPayment(Payment $payment, int $amount = null, string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $amount, $reason) {
            if ($payment->status !== 'succeeded') {
                throw new \RuntimeException('Can only refund succeeded payments');
            }

            $refundAmount = $amount ?? $payment->amount;

            // Create refund record
            $refund = $payment->refunds()->create([
                'amount' => $refundAmount,
                'reason' => $reason,
                'status' => 'pending',
            ]);

            // Process refund through provider
            // TODO: Implement provider-specific refund logic

            $refund->update(['status' => 'succeeded']);

            return $payment->fresh();
        });
    }

    public function getPaymentsByOrder(int $orderId): array
    {
        return $this->repository->findByOrder($orderId)->toArray();
    }
}
