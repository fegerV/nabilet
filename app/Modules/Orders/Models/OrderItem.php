<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Inventory\Models\InventoryItem;

/**
 * @property int $id
 * @property int $order_id
 * @property int $inventory_item_id
 * @property int $quantity
 * @property int $unit_price        integer minor units
 * @property int $discount_amount
 * @property int $fee_amount
 * @property int $total_amount
 * @property string $event_title_snapshot  NOT NULL — билет помнит название события
 * @property string|null $session_title_snapshot
 * @property string|null $venue_title_snapshot
 * @property array|null $seat_snapshot_json
 * @property \Carbon\CarbonImmutable $created_at
 *
 * В таблице НЕТ updated_at (см. тест order_items не должен его писать).
 */
class OrderItem extends Model
{
    use TenantScoped;
    public const UPDATED_AT = null;

    protected $table = 'order_items';

    /**
     * Глобальный читающий скоуп (девятая модель после Order, Payment, Ticket,
     * Refund, TicketScan, CheckinDevice, OfflineBundle и PaymentTransaction).
     *
     * Запись и так защищена трейтом (организация выводится из заказа через
     * TenantResolver). Чтения уже живут в нужном режиме и остаются в нём:
     *   - позиция заказа читается детьми авторизованного билета (`orderItem`
     *     в eager `TicketController::history`/`index`/`show` и в
     *     `TicketGeneratorService`, где выпуск идёт от заказа) — в bypass;
     *   - отдельных путей `OrderItem::query()` в приложении нет; связь
     *     `Order::orderItems()` без вызывающих.
     * Позиция не адресуется в маршрутах — route-binding не нужен.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected $fillable = [
        'order_id',
        'organization_id',
        'inventory_item_id',
        'quantity',
        'unit_price',
        'discount_amount',
        'fee_amount',
        'total_amount',
        'event_title_snapshot',
        'session_title_snapshot',
        'venue_title_snapshot',
        'seat_snapshot_json',
        // Заказ = билет: состояние билета хранится прямо в позиции.
        'public_id',
        'event_id',
        'session_id',
        'seat_id',
        'standing_zone_id',
        'ticket_number',
        'ticket_index',
        'qr_version',
        'qr_token_hash',
        'qr_payload',
        'holder_name',
        'ticket_status',
        'issued_at',
        'used_at',
        'refunded_at',
        'revoked_at',
        'revoked_reason',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'discount_amount' => 'integer',
        'fee_amount' => 'integer',
        'total_amount' => 'integer',
        'seat_snapshot_json' => 'array',
        'event_id' => 'integer',
        'session_id' => 'integer',
        'ticket_index' => 'integer',
        'qr_version' => 'integer',
        'issued_at' => 'datetime:Y-m-d H:i:s.u',
        'used_at' => 'datetime:Y-m-d H:i:s.u',
        'refunded_at' => 'datetime:Y-m-d H:i:s.u',
        'revoked_at' => 'datetime:Y-m-d H:i:s.u',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Алиасы для совместимости с кодом, который ещё работает с «билетом»:
     * после перехода tickets → VIEW над order_items все читатели получают
     * те же имена полей и связей, но данные идут из одной строки заказа.
     */
    public function getStatusAttribute(mixed $value): ?string
    {
        return $this->ticket_status;
    }

    public function getOrderItemAttribute(mixed $value): self
    {
        return $this;
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<\Nabilet\Modules\Tickets\Models\TicketScan, $this> */
    public function scans(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Tickets\Models\TicketScan::class, 'ticket_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Nabilet\Modules\Events\Models\Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(\Nabilet\Modules\Events\Models\Event::class, 'event_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}