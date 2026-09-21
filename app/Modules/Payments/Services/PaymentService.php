<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Repositories\PaymentRepository;
use App\Modules\Payments\Domain\PaymentStateMachine;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\SeatHold;
use App\Modules\Inventory\Services\HoldSweeper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        protected PaymentRepository $repository,
        protected PaymentStateMachine $stateMachine,
        protected HoldSweeper $holdSweeper
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
            'metadata' => $payload,
        ]);

        // Notify order service
        if ($payment->order) {
            $payment->order->service()->applyPayment($payment);
            
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

            // Prevent duplicate refunds
            if ($payment->refunds()->where('status', '!=', 'failed')->exists()) {
                // Check if total refunded amount already equals payment amount
                $totalRefunded = $payment->refunds()->where('status', 'succeeded')->sum('amount');
                if ($totalRefunded >= $payment->amount) {
                    throw new \RuntimeException('Payment already fully refunded');
                }
            }

            $refundAmount = $amount ?? ($payment->amount - ($payment->refunds()->where('status', 'succeeded')->sum('amount')));
            
            // Validate refund amount
            $totalRefunded = $payment->refunds()->where('status', 'succeeded')->sum('amount');
            if ($totalRefunded + $refundAmount > $payment->amount) {
                throw new \RuntimeException('Refund amount exceeds remaining payment balance');
            }

            // Create refund record
            $refund = $payment->refunds()->create([
                'public_id' => Str::uuid()->toString(),
                'amount' => $refundAmount,
                'reason' => $reason,
                'status' => 'pending',
                'provider_refund_id' => null,
                'metadata' => [],
            ]);

            // Process refund through provider based on payment provider type
            try {
                $providerName = $payment->provider ?? 'yookassa';
                
                // Get appropriate provider instance
                $provider = $this->getProvider($providerName);
                
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
                    'status' => 'pending', // Will be updated by webhook
                    'metadata' => array_merge($refund->metadata ?? [], ['provider_response' => $providerResponse]),
                ]);

                // Add refund transaction record
                $this->repository->addTransaction($payment, [
                    'type' => 'refund',
                    'amount' => -$refundAmount,
                    'status' => 'pending',
                    'metadata' => ['refund_id' => $refund->id, 'reason' => $reason],
                ]);

            } catch (\Exception $e) {
                // Mark refund as failed
                $refund->update([
                    'status' => 'failed',
                    'metadata' => array_merge($refund->metadata ?? [], ['failure_reason' => $e->getMessage()]),
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
                'yookassa' => '\\App\\Modules\\Payments\\Payments\\Providers\\YooKassaProvider',
                'stripe' => '\\App\\Modules\\Payments\\Payments\\Providers\\StripeProvider',
                'kaspi' => '\\App\\Modules\\Payments\\Payments\\Providers\\KaspiProvider',
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
}
