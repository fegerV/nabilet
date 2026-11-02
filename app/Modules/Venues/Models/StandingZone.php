<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

/**
 * StandingZone Model
 */
class StandingZone extends Model
{
    use TenantScoped;
    use HasPublicId;

    protected $table = 'standing_zones';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'sector_id',
        'name',
        'capacity',
        'price_amount',
        'currency',
    ];

    /**
     * Глобальный читающий скоуп (тридцать четвёртая модель — см. докблок
     * `Hall`). Карта: `sector_id` → `sectors`. Читатели — админская сборка
     * геометрии; витрина читает стоячие зоны из `schema_json` и
     * `inventory_items`.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'price_amount' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(\Nabilet\Modules\Inventory\Models\InventoryItem::class, 'standing_zone_id');
    }
}
