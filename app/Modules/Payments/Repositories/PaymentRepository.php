<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Repositories;

use Nabilet\Modules\Payments\Domain\TransactionType;
use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Models\PaymentTransaction;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
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

    /**
     * Платёж по внутреннему id строго в пределах организации.
     *
     * Организация обязательна: без неё чужой платёж (по `order.organization_id`)
     * стал бы виден. Для системных контекстов (вебхуки, reconcile) есть
     * `findAny()`.
     */
    public function find(int $id, int $organizationId): ?Payment
    {
        return $this->model->with(['order', 'transactions'])
            ->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->find($id);
    }

    /**
     * Платёж без скоупа организации — только для системных контекстов.
     */
    public function findAny(int $id): ?Payment
    {
        return $this->model->with(['order', 'transactions'])->find($id);
    }

    public function findByPublicId(string $publicId, int $organizationId): ?Payment
    {
        return $this->model->where('public_id', $publicId)
            ->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            ->with(['order', 'transactions'])
            ->first();
    }

    public function findAnyByPublicId(string $publicId): ?Payment
    {
        return $this->model->where('public_id', $publicId)
            ->with(['order', 'transactions'])
            ->first();
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

    /**
     * Единственный писатель журнала `payment_transactions`.
     *
     * Поэтому проверка пары (тип, статус) стоит ЗДЕСЬ, а не в каждом из шести
     * вызовов: словарь типа был объявлен докблоком модели, а писали его голыми
     * строками — седьмой писатель мог написать что угодно, и колонка
     * `type VARCHAR(64) NOT NULL` без CHECK это молча принимала. Правило живёт в
     * `TransactionType`, а этот метод его вызывает, так что обойти его можно
     * только правкой доменного класса.
     *
     * Исключение здесь безопасно: `type` и `status` никогда не берутся из
     * payload провайдера (только из констант приложения), поэтому отказать
     * вебхуку, который придёт повторно, эта проверка не может.
     */
    public function addTransaction(Payment $payment, array $transactionData): PaymentTransaction
    {
        TransactionType::assertAllowed(
            (string) ($transactionData['type'] ?? ''),
            isset($transactionData['status']) ? (string) $transactionData['status'] : null,
        );

        return $payment->transactions()->create($transactionData);
    }

    public function getSuccessfulPaymentsByOrganization(int $organizationId, string $dateFrom, string $dateTo): Collection
    {
        return $this->model->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            })
            // Таблица названа явно: запрос идёт по `payments`, а значение
            // берётся у владельца. Голый `where('status', 'succeeded')` не
            // сказал бы читателю, о каком статусе речь, и не попал бы под
            // проверку `verify-status-literals` (значение делят с `refunds`).
            ->where('payments.status', PaymentStateMachine::SUCCEEDED)
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
            'status' => PaymentStateMachine::SUCCEEDED,
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
            'status' => PaymentStateMachine::FAILED,
            'metadata_json' => $metadata,
        ]);
        return $payment;
    }

    /**
     * Отметить платёж отменённым провайдером (`payment.canceled`).
     *
     * Отдельно от `markAsFailed`: отменённый платёж — не неудачный, и путать их
     * нельзя (аудит A12). Метод существует, чтобы сервис не писал статус
     * напрямую мимо репозитория (аудит R-12).
     */
    public function markAsCanceled(Payment $payment): Payment
    {
        $payment->update([
            'status' => PaymentStateMachine::CANCELED,
        ]);

        return $payment;
    }

    public function findPendingWebhookPayments(): Collection
    {
        return $this->model->where('status', PaymentStateMachine::PENDING)
            ->whereNotNull('payment_url')
            ->get();
    }
}