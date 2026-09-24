<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Sector Model
 */
class Sector extends Model
{
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

        /** Конвенция проекта: char(26) public_id NOT NULL → генерим ULID при создании. */
        protected static function booted(): void
        {
            static::creating(function (Sector $model): void {
                if ($model->public_id === null) {
                    $model->public_id = Str::ulid()->toBase32();
                }
            });
        }
    }
