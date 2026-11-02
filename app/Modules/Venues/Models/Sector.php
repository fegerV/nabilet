<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sector Model
 */
class Sector extends Model
{
    use TenantScoped;
    use HasPublicId;

    protected $table = 'sectors';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'schema_version_id',
        'name',
        'code',
        'type',
        'x',
        'y',
        'width',
        'height',
        'color',
        'capacity',
    ];

    /**
     * Глобальный читающий скоуп (тридцать первая модель — см. докблок `Hall`).
     * Карта: `schema_version_id` → `hall_schema_versions`. Читатели —
     * геометрия схемы (админский инвентарь под контекстом); витрина места
     * берёт сектора из `schema_json`, а не из таблиц — eager `sectors` в
     * публичных цепочках не встречается, скоуп здесь fail-closed.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'x' => 'decimal:3',
            'y' => 'decimal:3',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'capacity' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(HallSchemaVersion::class, 'schema_version_id');
    }

    public function hallRows(): HasMany
    {
        return $this->hasMany(HallRow::class, 'sector_id');
    }

    public function hallTables(): HasMany
    {
        return $this->hasMany(HallTable::class, 'sector_id');
    }

    public function standingZones(): HasMany
        {
            return $this->hasMany(StandingZone::class, 'sector_id');
        }

    }
