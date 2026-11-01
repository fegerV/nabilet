<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;
use Nabilet\Modules\Core\Tenancy\TenantScoped;
use Nabilet\Modules\Orders\Models\Order;
use Nabilet\Modules\Orders\Models\OrderItem;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\Seat;
use Nabilet\Modules\Venues\Models\StandingZone;

/**
 * @property int $id
 * @property string $public_id
 * @property string $ticket_number
 * @property int $order_item_id
 * @property string $status issued|used|cancelled|refunded|expired|revoked
 *                          (ck_tickets_status — see nabilet_core_spec and
 *                          TicketStateMachine; 'checked_in'/'invalidated' are
 *                          NOT valid values)
 * @property string|null $qr_payload signed NB1.<id>.<token>.<sig> payload
 * @property \Carbon\Carbon $created_at
 */
class Ticket extends Model
{
    use HasPublicId;
    use TenantScoped;

    protected $table = 'tickets';

    protected $fillable = [
        'public_id',
        'ticket_number',
        'ticket_index',
        'order_id',
        'order_item_id',
        'event_id',
        'session_id',
        'inventory_item_id',
        'seat_id',
        'standing_zone_id',
        'holder_name',
        'status',
        'qr_version',
        'qr_token_hash',
        'qr_payload',
        'issued_at',
        'used_at',
        'cancelled_at',
        'refunded_at',
        'expired_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected $casts = [
        'ticket_index' => 'integer',
        'qr_version' => 'integer',
        'issued_at' => 'datetime',
        'used_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'expired_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Глобальный читающий скоуп (третья модель после Order и Payment).
     *
     * Пути со СВОЕЙ защитой арендатора работают в без-скоупном режиме:
     *   - проверка-in чекера идёт по токену устройства и несёт
     *     `organizationId` прямо в вызовах `scan()`/`canCheckin()`;
     *   - «мои билеты» и выпуск билетов читают детей уже авторизованного
     *     родителя (заказ пользователя / вебхук об оплате).
     * HTTP-запрос без организации здесь означает ошибку, а не «показать все
     * билеты».
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    /**
     * Резолв route-model-binding вне глобального тенант-скоупа.
     *
     * Те же рассуждения, что у `Order::resolveRouteBinding()`: маршрут адресует
     * билет по id, а доступ решается ПОСЛЕ резолва (`assertCanSee()` —
     * владелец/сотрудник, чекин — организация устройства). Резолв под скоупом
     * валил бы владельца-покупателя `TenantContextMissingError`'ом ещё до
     * проверки доступа.
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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(TicketScan::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    public function standingZone(): BelongsTo
    {
        return $this->belongsTo(StandingZone::class);
    }
}
