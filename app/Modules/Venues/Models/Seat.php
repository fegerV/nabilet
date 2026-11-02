<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Seat Model
 */
class Seat extends Model
{
    use TenantScoped;
    use HasPublicId;

    protected $table = 'seats';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'row_id',
        'number',
        'price_amount',
        'label',
        'x',
        'y',
        'width',
        'height',
        'rotation',
        'type',
        'status',
        'metadata_json',
    ];

    /**
     * Глобальный читающий скоуп (тридцать третья модель — см. докблок `Hall`).
     * Карта: `row_id` → `hall_rows`. Витрина мест читает координаты из
     * `schema_json`, а сами места живут в `inventory_items` (своё скоуп-шаг
     * InventoryItem): таблица `seats` адресуется админскими сборками геометрии
     * и скоуп здесь fail-closed ограждение. Связь `inventoryItems` в
     * фолбэке ресурса сеанса разрешается под контекстом админки либо в
     * агрегате `hydrateAvailableSeats` — уже в bypass с шага InventoryItem.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'price_amount' => 'integer',
            'x' => 'decimal:3',
            'y' => 'decimal:3',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'rotation' => 'decimal:3',
            'metadata_json' => 'array',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function row(): BelongsTo
    {
        return $this->belongsTo(HallRow::class, 'row_id');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Inventory\Models\InventoryItem::class, 'seat_id');
    }

    public function tickets(): HasMany
        {
            return $this->hasMany(\Nabilet\Modules\Tickets\Models\Ticket::class, 'seat_id');
        }

    }
