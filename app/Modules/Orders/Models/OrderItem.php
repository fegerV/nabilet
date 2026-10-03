<?php

declare(strict_types=1);

namespace Nabilet\Modules\Orders\Models;

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
    public const UPDATED_AT = null;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
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
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'discount_amount' => 'integer',
        'fee_amount' => 'integer',
        'total_amount' => 'integer',
        'seat_snapshot_json' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}