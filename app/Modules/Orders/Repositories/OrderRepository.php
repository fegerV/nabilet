<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Repositories;

use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\OrderItem;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderRepository
{
    public function __construct(
        protected Order $model
    ) {}

    public function find(int $id, ?int $organizationId = null): ?Order
    {
        $query = $this->model->with(['items.inventoryItem', 'customer', 'payments']);
        
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }
        
        return $query->find($id);
    }

    public function findByPublicId(string $publicId, ?int $organizationId = null): ?Order
    {
        $query = $this->model->where('public_id', $publicId);
        
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }
        
        return $query->with(['items.inventoryItem', 'customer', 'payments'])->first();
    }

    public function findByOrganization(int $organizationId, array $filters = [], int $limit = 15): LengthAwarePaginator
        {
                $query = $this->model->where('organization_id', $organizationId)
                    ->with(['items']);

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['session_id'])) {
                $query->where('session_id', $filters['session_id']);
            }

            if (isset($filters['customer_email'])) {
                $query->where('customer_email', $filters['customer_email']);
            }

            if (isset($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            return $query->orderBy('created_at', 'desc')->paginate($limit);
        }

        /**
     * Все заказы (админка / список «мои заказы» с принудительным user_id).
     *
     * Единственный метод постраничного списка. До этого рядом жил
     * `paginateByOrganization` с ДРУГИМ набором фильтров (`user_id` работал,
     * `customer_email` и даты — нет), и выбор между ними зависел от того,
     * передан ли `organization_id`: `GET /orders?user_id=5` молча возвращал все
     * заказы системы. Метод удалён, `organization_id` — обычный фильтр здесь.
     *
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginateAll(array $filters = [], int $limit = 20): LengthAwarePaginator
        {
            $query = $this->model->with(['items']);

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (! empty($filters['user_id'])) {
                $query->where('user_id', $filters['user_id']);
            }

            if (! empty($filters['organization_id'])) {
                $query->where('organization_id', $filters['organization_id']);
            }

            if (! empty($filters['customer_email'])) {
                // `ilike` — оператор PostgreSQL; MySQL его не знает и падает с
                // SQLSTATE[42000] (500). Сравнение и так регистронезависимое:
                // коллация utf8mb4_unicode_ci. `%`/`_` из ввода экранируем,
                // иначе поиск по «a_b» совпал бы с «aXb».
                $query->where('customer_email', 'like', '%' . addcslashes((string) $filters['customer_email'], '%_\\') . '%');
            }

            if (isset($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            return $query->orderBy('created_at', 'desc')->paginate($limit);
        }

    public function create(array $data): Order
    {
        return $this->model->create($data);
    }

    public function update(Order $order, array $data): Order
    {
        $order->update($data);
        return $order->fresh();
    }

    public function cancel(Order $order): Order
    {
        $order->update(['status' => 'cancelled']);
        return $order;
    }

    public function complete(Order $order): Order
    {
        $order->update(['status' => 'completed']);
        return $order;
    }

    public function addItem(Order $order, array $itemData): OrderItem
    {
        return $order->items()->create($itemData);
    }

    public function getRevenueByOrganization(int $organizationId, string $dateFrom, string $dateTo): float
        {
            return (float) $this->model->where('organization_id', $organizationId)
                ->where('status', 'paid')
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->sum('total_amount');
        }

    public function getCountByStatus(int $organizationId): array
    {
        return $this->model->where('organization_id', $organizationId)
            ->select('status', \DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }
}
