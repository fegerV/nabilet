<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $schema_version_id
 * @property string $name
 * @property string $code
 * @property string $type
 * @property float $x
 * @property float $y
 * @property float|null $width
 * @property float|null $height
 * @property string|null $color
 * @property int|null $capacity
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Sector extends Model
{
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

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'width' => 'float',
        'height' => 'float',
        'capacity' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(HallSchemaVersion::class, 'schema_version_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(HallRow::class, 'sector_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(HallTable::class, 'sector_id');
    }

    public function standingZones(): HasMany
    {
        return $this->hasMany(StandingZone::class, 'sector_id');
    }

        protected static function boot(): void
        {
            parent::boot();
            static::creating(function (self $model) {
                if (empty($model->public_id)) {
                    $model->public_id = (string) \Illuminate\Support\Str::ulid()->toBase32();
                }
            });
        }

}
