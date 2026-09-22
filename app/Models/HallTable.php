<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $sector_id
 * @property string $name
 * @property float $x
 * @property float $y
 * @property float $width
 * @property float $height
 * @property float $rotation
 * @property int|null $capacity
 * @property array|null $metadata_json
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class HallTable extends Model
{
    protected $fillable = [
        'public_id',
        'sector_id',
        'name',
        'x',
        'y',
        'width',
        'height',
        'rotation',
        'capacity',
        'metadata_json',
    ];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'width' => 'float',
        'height' => 'float',
        'rotation' => 'float',
        'capacity' => 'integer',
        'metadata_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }
}
