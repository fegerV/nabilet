<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HallRow Model
 */
class HallRow extends Model
{
    use TenantScoped;
    use HasPublicId;

    protected $table = 'hall_rows';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'sector_id',
        'number',
        'name',
        'price_amount',
        'currency',
        'x',
        'y',
        'rotation',
    ];

    /**
     * Глобальный читающий скоуп (тридцать вторая модель — см. докблок `Hall`).
     * Карта: `sector_id` → `sectors`. Читатели — админская сборка геометрии
     * (контекст есть); на витрине ряды ездят внутри `schema_json`.
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
            'rotation' => 'decimal:3',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }

    public function seats(): HasMany
        {
            return $this->hasMany(Seat::class, 'row_id');
        }

    }
