<?php

declare(strict_types=1);

namespace Nabilet\Modules\Analytics\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * @property int $id
 * @property int $ab_experiment_id
 * @property int|null $user_id
 * @property string $visitor_id
 * @property int $ab_variant_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class AbAssignment extends Model
{
    use TenantScoped;
    protected $table = 'ab_assignments';

    /**
     * Глобальный читающий скоуп (пятнадцатая модель после Order, Payment,
     * Ticket, Refund, TicketScan, CheckinDevice, OfflineBundle,
     * PaymentTransaction, OrderItem, SeatHold, CartItem, Cart,
     * PromoCodeRedemption и WebhookDelivery).
     *
     * Запись защищена трейтом (организация выводится из A/B-эксперимента
     * через TenantResolver: `ab_assignments` → `ab_experiments`; фаза 3
     * сделала колонку NOT NULL). Присвоение не адресуется в маршрутах —
     * route-binding не нужен. Читателей в приложении сейчас нет: A/B-
     * функциональность не собрана. Включение скоупа — fail-closed задел:
     * будущее чтение без контекста получит явный отказ, а не данные чужих
     * организаций.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected $fillable = [
        'ab_experiment_id',
        'user_id',
        'visitor_id',
        'ab_variant_id',
    ];

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(AbExperiment::class, 'ab_experiment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(AbVariant::class, 'ab_variant_id');
    }
}
