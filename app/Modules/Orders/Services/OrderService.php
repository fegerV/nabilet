<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Services;

use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Repositories\OrderRepository;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderService
{
    public function __construct(
        protected OrderRepository $repository,
        protected OrderStateMachine $stateMachine
    ) {}

    public function createOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $order = $this->repository->create([
                'public_id' => Str::uuid()->toString(),
                'organization_id' => $data['organization_id'],
                'session_id' => $data['session_id'],
                'customer_email' => $data['customer_email'],
                'customer_name' => $data['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'status' => 'pending',
                'total_amount' => 0,
                'currency' => $data['currency'] ?? 'KZT',
                'metadata' => $data['metadata'] ?? [],
            ]);

            // Add order items
            $totalAmount = 0;
            foreach ($data['items'] ?? [] as $itemData) {
                $inventoryItem = InventoryItem::findOrFail($itemData['inventory_item_id']);
                
                $orderItem = $this->repository->addItem($order, [
                    'inventory_item_id' => $inventoryItem->id,
                    'quantity' => $itemData['quantity'] ?? 1,
                    'unit_price' => $inventoryItem->price,
                    'total_price' => ($itemData['quantity'] ?? 1) * $inventoryItem->price,
                ]);

                $totalAmount += $orderItem->total_price;

                // Reserve inventory
                $inventoryItem->decrement('available_quantity', $orderItem->quantity);
            }

            $order->update(['total_amount' => $totalAmount]);

            return $order->load(['items.inventoryItem']);
        });
    }

    public function findOrder(int $orderId, int $organizationId = null): ?Order
    {
        return $this->repository->find($orderId, $organizationId);
    }

    public function findByPublicId(string $publicId, int $organizationId = null): ?Order
    {
        return $this->repository->findByPublicId($publicId, $organizationId);
    }

    public function cancelOrder(Order $order, string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            // Check if we can cancel
            if (!$this->stateMachine->canTransition($order, 'cancelled')) {
                throw new \RuntimeException('Order cannot be cancelled');
            }

            // Release inventory
            foreach ($order->items as $item) {
                $item->inventoryItem->increment('available_quantity', $item->quantity);
            }

            $order = $this->repository->cancel($order);

            if ($reason) {
                $order->metadata = array_merge($order->metadata ?? [], ['cancellation_reason' => $reason]);
                $order->save();
            }

            return $order;
        });
    }

    public function completeOrder(Order $order): Order
    {
        if (!$this->stateMachine->canTransition($order, 'completed')) {
            throw new \RuntimeException('Order cannot be completed');
        }

        return $this->repository->complete($order);
    }

    public function applyPayment(Order $order, Payment $payment): Order
    {
        return DB::transaction(function () use ($order, $payment) {
            // Link payment to order
            $payment->update(['order_id' => $order->id]);

            // Check if fully paid
            $paidAmount = $order->payments()->sum('amount');
            
            if ($paidAmount >= $order->total_amount) {
                $this->completeOrder($order);
            }

            return $order->fresh();
        });
    }

    public function getOrdersByOrganization(int $organizationId, array $filters = [], int $limit = 15)
    {
        return $this->repository->findByOrganization($organizationId, $filters, $limit);
    }

    public function getRevenueReport(int $organizationId, string $dateFrom, string $dateTo): float
    {
        return $this->repository->getRevenueByOrganization($organizationId, $dateFrom, $dateTo);
    }
}
