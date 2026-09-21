<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Repositories;

use Nabilet\Modules\Payments\Models\Payment;
use Nabilet\Modules\Payments\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Collection;

class PaymentRepository
{
    public function __construct(
        protected Payment $model
    ) {}

    public function find(int $id, int $organizationId = null): ?Payment
    {
        $query = $this->model->with(['order', 'transactions']);
        
        if ($organizationId) {
            $query->whereHas('order', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });
        }
        
        return $query->find($id);
    }

    public function findByPublicId(string $publicId, int $organizationId = null): ?Payment
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

    public function markAsSucceeded(Payment $payment): Payment
    {
        $payment->update([
            'status' => 'succeeded',
            'succeeded_at' => now(),
        ]);
        return $payment;
    }

    public function markAsFailed(Payment $payment, string $failureCode = null, string $failureMessage = null): Payment
    {
        $payment->update([
            'status' => 'failed',
            'failure_code' => $failureCode,
            'failure_message' => $failureMessage,
            'failed_at' => now(),
        ]);
        return $payment;
    }

    public function findPendingWebhookPayments(): Collection
    {
        return $this->model->where('status', 'pending')
            ->whereNotNull('webhook_url')
            ->get();
    }
}
