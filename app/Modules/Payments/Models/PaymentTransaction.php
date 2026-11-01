<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payment_id
 * @property string|null $provider_event_id  UNIQUE (payment_id, provider_event_id)
 * @property string $type                    TransactionType::AUTHORIZATION | CAPTURE | REFUND | FAILURE
 * @property int|null $amount
 * @property string|null $currency
 * @property string|null $status            см. TransactionType::statusesFor($type)
 * @property array|null $payload_json
 * @property \Carbon\CarbonImmutable $created_at
 *
 * В таблице НЕТ updated_at — модель не должна его писать (как OrderItem).
 *
 * Словарь `type` и правило «какой статус возможен при каком типе» объявлены НЕ
 * здесь, а в `Nabilet\Modules\Payments\Domain\TransactionType`: докблок не
 * запрещает ничего, и пока значения были записаны только в нём, шесть писателей
 * повторяли их строками, а `type VARCHAR(64) NOT NULL` без CHECK принимал любую.
 */
class PaymentTransaction extends Model
{
    use TenantScoped;
    public const UPDATED_AT = null;

    protected $table = 'payment_transactions';

    /**
     * Глобальный читающий скоуп (восьмая модель после Order, Payment, Ticket,
     * Refund, TicketScan, CheckinDevice и OfflineBundle).
     *
     * Запись и так была защищена трейтом: организация выводится из платежа
     * через TenantResolver. Чтения уже живут в нужном режиме и остаются в нём:
     *   - транзакции читаются детьми авторизованного платежа: show/refund
     *     (`PaymentController`) и выпуск (`createPaymentScoped`) — в bypass;
     *   - список платежей сотрудника — под его организацией (фильтры
     *     совпадают); покупателя — bypass вокруг детей собственных платежей;
     *   - вебхуки и reconcile (`processWebhookScoped`) — в bypass целиком.
     * Транзакция не адресуется в маршрутах — route-binding не нужен.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected $fillable = [
        'payment_id',
        'provider_event_id',
        'type',
        'amount',
        'currency',
        'status',
        'payload_json',
    ];

    protected $casts = [
        'amount' => 'integer',
        'payload_json' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}