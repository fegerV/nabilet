<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Repositories;

use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PaymentRepository
{
    public function __construct(
        protected Payment $model
    ) {}

    /**
     * Постраничный список платежей.
     *
     * Живёт здесь, а не в сервисе: `$model` объявлен `protected`, поэтому
     * прежний `$this->repository->model->newQuery()` из `PaymentService` был
     * фаталом «Cannot access protected property» — он просто никогда не
     * выполнялся, потому что сервис уходил в ветку «нет organization_id →
     * пустой результат» раньше. `organization_id` при этом до сервиса не
     * доходил вовсе: контроллер резал фильтры через `only()`, где его не было.
     *
     * Фильтр `user_id` идёт через `order` — владелец платежа это владелец
     * заказа; у `payments` своей колонки `user_id` нет.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Payment>
     */
    public function paginate(array $filters = [], int $limit = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with(['order', 'transactions']);

        if (! empty($filters['organization_id'])) {
            $query->whereHas('order', fn ($q) => $q->where('organization_id', $filters['organization_id']));
        }

        if (! empty($filters['user_id'])) {
            $query->whereHas('order', fn ($q) => $q->where('user_id', $filters['user_id']));
        }

        if (! empty($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['provider'])) {
            $query->where('provider', $filters['provider']);
        }

        return $query->orderByDesc('created_at')->paginate($limit);
    }

    public function find(int $id, ?int $organizationId = null): ?Payment
    {
        $query = $this->model->with(['order', 'transactions']);

        if ($organizationId) {
            $query->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });
        }

        return $query->find($id);
    }

    public function findByPublicId(string $publicId, ?int $organizationId = null): ?Payment
    {
        $query = $this->model->where('public_id', $publicId);

        if ($organizationId) {
            $query->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });
        }

        return $query->with(['order', 'transactions'])->first();
    }

    public function findByOrder(int $orderId): Collection
    {
        return $this->model->where('order_id', $orderId)
            ->with(['transactions'])
            ->get();
    }

    public function create(array $data): Payment
    {
        return $this->model->create($data);
    }

    public function update(Payment $payment, array $data): Payment
    {
        $payment->update($data);
        return $payment->fresh();
    }

    public function addTransaction(Payment $payment, array $transactionData): PaymentTransaction
    {
        return $payment->transactions()->create($transactionData);
    }

    public function getSuccessfulPaymentsByOrganization(int $organizationId, string $dateFrom, string $dateTo): Collection
    {
        return $this->model->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->where('status', 'succeeded')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->with(['order'])
            ->get();
    }

    public function getTotalAmountByStatus(int $organizationId): array
    {
        return $this->model->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->select('status', \DB::raw('sum(amount) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
    }

    /**
     * Отметить платёж успешным. В схеме `paid_at`, а не `succeeded_at`.
     */
    public function markAsSucceeded(Payment $payment): Payment
    {
        $payment->update([
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);
        return $payment;
    }

    /**
     * Отметить платёж неудавшимся. Причина пишется в `metadata_json` под ключом
     * `failure` — отдельных колонок failure_code/failure_message/failed_at в схеме нет.
     */
    public function markAsFailed(Payment $payment, ?string $failureCode = null, ?string $failureMessage = null): Payment
    {
        $metadata = (array) ($payment->metadata_json ?? []);
        $metadata['failure'] = [
            'code' => $failureCode,
            'message' => $failureMessage,
        ];

        $payment->update([
            'status' => 'failed',
            'metadata_json' => $metadata,
        ]);
        return $payment;
    }

    public function findPendingWebhookPayments(): Collection
    {
        return $this->model->where('status', 'pending')
            ->whereNotNull('payment_url')
            ->get();
    }
}