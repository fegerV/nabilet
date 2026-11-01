<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Models;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Core\Tenancy\TenantScoped;

/**
 * @property int $id
 * @property string $public_id       CHAR(26), ULID base32
 * @property int $order_id           NOT NULL по схеме
 * @property string $provider        'yookassa' | 'stripe' | ...
 * @property string|null $provider_payment_id
 * @property int $amount             integer minor units
 * @property string $currency
 * @property string $status          из ck_payments_status: pending|waiting_for_capture|succeeded|canceled|failed
 * @property string|null $payment_url
 * @property string $idempotency_key NOT NULL по схеме
 * @property array|null $metadata_json
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable|null $paid_at
 * @property \Carbon\CarbonImmutable $updated_at
 *
 * `organization_id` добавлен фазой 1 (nullable); method/webhook_url/succeeded_at/failure_* НЕТ.
 */
class Payment extends Model
{
    use HasPublicId;
    use TenantScoped;

    protected $table = 'payments';

    protected $fillable = [
        'public_id',
        'order_id',
        'provider',
        'provider_payment_id',
        'amount',
        'currency',
        'status',
        'payment_url',
        'idempotency_key',
        'metadata_json',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'metadata_json' => 'array',
        'paid_at' => 'datetime',
    ];

    /**
     * Глобальный читающий скоуп.
     *
     * Все системные пути чтения платежей уже работают внутри
     * `OrganizationContext::withoutScope()`: `initiatePayment()`,
     * `createPayment()`, `processWebhook()`, reconcile и сверка демо-платежа.
     * Поэтому запрос из HTTP-контекста без организации здесь означает именно
     * ошибку, а не «покажи все платежи».
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    /**
     * Резолв route-model-binding вне глобального тенант-скоупа.
     *
     * Тот же паттерн, что у `Order`/`Ticket`: `GET /payments/{payment}` и
     * `POST /payments/{payment}/refund` адресуют платёж по id, а доступ
     * решается ПОСЛЕ резолва (`assertCanSee()` — владелец заказа, гость с
     * capability-токеном корзины, сотрудник своей организации). Залогиненный
     * покупатель не имеет организации в контексте — резолв под скоупом валил бы
     * его 500 `TenantContextMissingError` ещё до проверки доступа.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return app(OrganizationContext::class)->withoutScope(
            fn (): ?Model => parent::resolveRouteBinding($value, $field)
        );
    }

    protected static function booted(): void
    {
        // `public_id` заполняет трейт HasPublicId; здесь — ключ идемпотентности,
        // который защищает от двойного списания при повторном запросе.
        static::creating(function (Payment $model): void {
            if ($model->idempotency_key === null) {
                $model->idempotency_key = (string) Str::ulid();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}