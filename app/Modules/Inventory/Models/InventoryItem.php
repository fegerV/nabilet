<?php

declare(strict_types=1);

namespace Nabilet\Modules\Inventory\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;
use Nabilet\Modules\Events\Models\Session;
use Nabilet\Modules\Orders\Models\SeatHold;
use Nabilet\Modules\Venues\Models\Seat;
use Nabilet\Modules\Inventory\StateMachines\InventoryItemStateMachine;
use Nabilet\Modules\Venues\Models\StandingZone;

/**
 * InventoryItem — продаваемое место/зона на сессию.
 * Реальная схема (inventory_items): price_amount BIGINT, currency char(3),
 * capacity/available_quantity INT, status, metadata_json, public_id NOT NULL.
 */
class InventoryItem extends Model
{
    use TenantScoped;
    use HasPublicId;

    protected $table = 'inventory_items';

    protected $fillable = [
        'public_id',
        'session_id',
        'seat_id',
        'standing_zone_id',
        'type',
        'price_amount',
        'currency',
        'capacity',
        'available_quantity',
        'status',
        'metadata_json',
    ];

    protected $casts = [
        'price_amount' => 'integer',
        'capacity' => 'integer',
        'available_quantity' => 'integer',
        'metadata_json' => 'array',
    ];

    /**
     * Глобальный читающий скоуп (восемнадцатая модель после Order, Payment,
     * Ticket, Refund, TicketScan, CheckinDevice, OfflineBundle,
     * PaymentTransaction, OrderItem, SeatHold, CartItem, Cart,
     * PromoCodeRedemption, WebhookDelivery, AbAssignment, AbVariant и
     * AbMetric).
     *
     * Запись защищена трейтом (организация выводится из сеанса через
     * TenantResolver: `inventory_items` → `sessions`; фаза 3 сделала колонку
     * NOT NULL). Витринная карта зала по своей природе публична: гость читает
     * инвентарь сеанса по `session_id` без тенант-контекста (у white-label
     * витрин контекст ставится по Host, но у витрины на общем домене его
     * нет), поэтому публичные пути — витрина сеанса, embed-карта, позиции
     * `GET /inventory` и билборд `availability`, добавление позиции в корзину
     * — в bypass. Скоуп остаётся fail-closed ограждением для будущих читателей
     * и сужает выборку staff-путям, где контекст есть и совпадает с
     * организацией: `updateRowPrices`, пересчёт `seatsInUse`, отчёты.
     * Системные пути (списание при чекауте, `markInventorySoldForOrder`,
     * освобождения при отмене/sweeper) либо raw `DB::table`, либо уже в
     * bypass с шагов Order/Cart.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if ($model->currency === null) {
                $model->currency = 'RUB';
            }
            if ($model->capacity === null) {
                $model->capacity = 1;
            }
            if ($model->available_quantity === null) {
                $model->available_quantity = $model->capacity;
            }
            if ($model->status === null) {
                $model->status = InventoryItemStateMachine::AVAILABLE;
            }
        });
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

    public function holds(): HasMany
    {
        return $this->hasMany(SeatHold::class, 'inventory_item_id');
    }
}