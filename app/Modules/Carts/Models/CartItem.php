<?php

declare(strict_types=1);

namespace App\Modules\Carts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Inventory\Models\InventoryItem;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $inventory_item_id
 * @property int $quantity
 * @property string $unit_price
 * @property string $total_price
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class CartItem extends Model
{
    protected $table = 'cart_items';

    protected $fillable = [
        'cart_id',
        'inventory_item_id',
        'quantity',
        'unit_price',
        'total_price',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'string',
        'total_price' => 'string',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
