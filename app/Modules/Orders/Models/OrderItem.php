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
 * @property string $unit_price
 * @property string $total_price
 * @property string|null $metadata
 * @property \Carbon\CarbonImmutable $created_at
 */
class OrderItem extends Model
{
    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'inventory_item_id',
        'quantity',
        'unit_price',
        'total_price',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'string',
        'total_price' => 'string',
        'metadata' => 'array',
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
