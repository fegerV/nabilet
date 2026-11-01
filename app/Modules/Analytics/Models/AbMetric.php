<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ab_experiment_id
 * @property string $name
 * @property string $goal_type 'conversion' | 'revenue' | 'engagement'
 * @property array $configuration
 */
class AbMetric extends Model
{
    use TenantScoped;
    protected $table = 'ab_metrics';

    /**
     * Глобальный читающий скоуп (семнадцатая модель после Order, Payment,
     * Ticket, Refund, TicketScan, CheckinDevice, OfflineBundle,
     * PaymentTransaction, OrderItem, SeatHold, CartItem, Cart,
     * PromoCodeRedemption, WebhookDelivery, AbAssignment и AbVariant).
     *
     * Запись защищена трейтом (организация выводится из A/B-эксперимента
     * через TenantResolver: `ab_metrics` → `ab_experiments`; фаза 3
     * сделала колонку NOT NULL). Метрика не адресуется в маршрутах —
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
        'goal_type',
        'configuration',
    ];

    protected $casts = [
        'configuration' => 'array',
    ];

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'ab_experiment_id');
    }
}
