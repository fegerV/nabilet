<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ab_experiment_id
 * @property string $name 'control' | 'variant_a' | 'variant_b'
 * @property float $weight
 * @property array $configuration
 */
class AbVariant extends Model
{
    use TenantScoped;
    protected $table = 'ab_variants';

    /**
     * Глобальный читающий скоуп (шестнадцатая модель после Order, Payment,
     * Ticket, Refund, TicketScan, CheckinDevice, OfflineBundle,
     * PaymentTransaction, OrderItem, SeatHold, CartItem, Cart,
     * PromoCodeRedemption, WebhookDelivery и AbAssignment).
     *
     * Запись защищена трейтом (организация выводится из A/B-эксперимента
     * через TenantResolver: `ab_variants` → `ab_experiments`; фаза 3
     * сделала колонку NOT NULL). Вариант не адресуется в маршрутах —
     * route-binding не нужен. Читателей в приложении сейчас нет: A/B-
     * функциональность не собрана — включение скоупа fail-closed задел
     * (см. докблок `AbAssignment`).
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected $fillable = [
        'ab_experiment_id',
        'name',
        'weight',
        'configuration',
    ];

    protected $casts = [
        'weight' => 'float',
        'configuration' => 'array',
    ];

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'ab_experiment_id');
    }
}
