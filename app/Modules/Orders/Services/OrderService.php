<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Services;

use Nabilet\Core\Errors\InvalidStateTransitionError;
use Nabilet\Core\StateMachine\StateMachine;
use Nabilet\Modules\Inventory\Models\InventoryItem;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Repositories\OrderRepository;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\Models\Payment;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OrderService
{
    private StateMachine $machine;

    public function __construct(
        protected OrderRepository $repository
    ) {
        $this->machine = OrderStateMachine::make();
    }

    public function createOrder(array $data): Order
    {
        return DB::transaction(function () use ($data) {
            $inventoryItems = [];
            $totalAmount = 0;
            foreach ($data['items'] ?? [] as $itemData) {
                $item = InventoryItem::findOrFail($itemData['inventory_item_id']);
                $quantity = max(1, (int) ($itemData['quantity'] ?? 1));

                // Доступность проверяем до списания: БД это тоже запрещает
                // (ck_inventory_available_qty), но проверка на уровне приложения
                // даёт внятную доменную ошибку вместо SQL CHECK violation.
                if ($quantity > (int) $item->available_quantity) {
                    throw new \Nabilet\Core\Errors\DomainRuleViolation(
                        'Not enough inventory available',
                        'INVENTORY_NOT_AVAILABLE',
                        ['inventory_item_id' => $item->id, 'requested' => $quantity, 'available' => (int) $item->available_quantity],
                    );
                }

                $inventoryItems[] = [$item, $quantity];
                $totalAmount += $quantity * (int) $item->price_amount;
            }

            $order = $this->repository->create([
                'organization_id' => $data['organization_id'],
                'user_id' => $data['user_id'] ?? null,
                'status' => 'pending',
                'payment_status' => 'pending',
                'subtotal_amount' => $totalAmount,
                'discount_amount' => 0,
                'fee_amount' => 0,
                'total_amount' => $totalAmount,
                'currency' => $data['currency'] ?? 'RUB',
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'] ?? null,
            ]);

            foreach ($inventoryItems as [$inventoryItem, $quantity]) {
                $orderItem = $this->repository->addItem($order, [
                    'inventory_item_id' => $inventoryItem->id,
                    'quantity' => $quantity,
                    'unit_price' => $inventoryItem->price_amount,
                    'total_amount' => $quantity * $inventoryItem->price_amount,
                    'event_title_snapshot' => $inventoryItem->session?->event?->title
                        ?? $data['event_title'] ?? '',
                    'session_title_snapshot' => $inventoryItem->session?->title ?? null,
                    'venue_title_snapshot' => $inventoryItem->session?->venue?->name ?? null,
                ]);

                // Reserve inventory
                $inventoryItem->decrement('available_quantity', $orderItem->quantity);
            }

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

    /**
     * Постраничный список заказов для API.
     *
     * Fail-closed: если организация не определена, возвращается пустая страница,
     * а не заказы всех арендаторов. У модели Order нет глобального tenant-скоупа,
     * поэтому это единственный барьер между клиентом и чужими заказами.
     *
     * @param  array{organization_id?: int|string|null, status?: string|null, user_id?: int|string|null}  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $organizationId = $filters['organization_id'] ?? null;

        if ($organizationId === null || $organizationId === '' || (int) $organizationId <= 0) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        return $this->repository->paginateByOrganization((int) $organizationId, $filters, $perPage);
    }

    /**
     * Разрешён ли переход из текущего статуса заказа.
     */
    public function canTransition(Order $order, string $to): bool
    {
        return $this->machine->can($order->status, $to);
    }

    public function markAwaitingPayment(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            // Идемпотентность: повторный вызов на уже ожидающем оплату заказе — норм.
            if ($order->status === OrderStateMachine::AWAITING_PAYMENT) {
                return $order->fresh();
            }

            $this->machine->assert($order->status, OrderStateMachine::AWAITING_PAYMENT);
            $order->update(['status' => OrderStateMachine::AWAITING_PAYMENT]);
            return $order->fresh();
        });
    }

    public function markPaid(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $this->assertNotTerminal($order);
            $this->machine->assert($order->status, OrderStateMachine::PAID);
            $now = now();
            $order->update([
                'status' => OrderStateMachine::PAID,
                'payment_status' => 'succeeded',
                'paid_at' => $now,
            ]);
            return $order->fresh();
        });
    }

    public function markPaymentFailed(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            $this->machine->assert($order->status, OrderStateMachine::PAYMENT_FAILED);
            $order->update(['status' => OrderStateMachine::PAYMENT_FAILED]);
            return $order->fresh();
        });
    }

    public function cancelOrder(Order $order, string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            // Check if we can cancel
            if (!$this->machine->can($order->status, OrderStateMachine::CANCELLED)) {
                throw new InvalidStateTransitionError(
                    'Order',
                    $order->status,
                    OrderStateMachine::CANCELLED,
                    $this->machine->allowedFrom($order->status),
                );
            }

            // Release inventory
            foreach ($order->items as $item) {
                $item->inventoryItem->increment('available_quantity', $item->quantity);
            }

            $order = $this->repository->cancel($order);

            $order->update(['cancelled_at' => now()]);

            // В схеме orders нет колонки metadata — причина отмены фиксируется
            // штатной колонкой cancelled_at; сам факт отмены достаточен.

            return $order;
        });
    }

    public function completeOrder(Order $order): Order
    {
        if (!$this->machine->can($order->status, OrderStateMachine::PAID)) {
            throw new InvalidStateTransitionError(
                'Order',
                $order->status,
                OrderStateMachine::PAID,
                $this->machine->allowedFrom($order->status),
            );
        }

        return $this->repository->complete($order);
    }

    public function applyPayment(Order $order, Payment $payment): Order
    {
        return DB::transaction(function () use ($order, $payment) {
            // If already paid, this is a replay — idempotent.
            if ($order->status === OrderStateMachine::PAID) {
                return $order->fresh();
            }

            // Check if fully paid
            $paidAmount = (int) $order->payments()->where('status', 'succeeded')->sum('amount');

            if ($paidAmount >= (int) $order->total_amount) {
                // Прямой pending -> paid запрещён машиной; но здесь платёж уже
                // прошёл (webhook succeeded), поэтому переводим через awaiting.
                if ($order->status === OrderStateMachine::PENDING) {
                    $this->markAwaitingPayment($order->fresh());
                }
                $this->markPaid($order->fresh());
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

    private function assertNotTerminal(Order $order): void
    {
        if (OrderStateMachine::isTerminal($order->status)) {
            throw new InvalidStateTransitionError(
                'Order',
                $order->status,
                OrderStateMachine::PAID,
                [],
            );
        }
    }
}