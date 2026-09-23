<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $sector_id
 * @property string $number
 * @property string|null $name
 * @property int $price_amount
 * @property string $currency
 * @property float|null $x
 * @property float|null $y
 * @property float $rotation
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class HallRow extends Model
{
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

    protected $casts = [
        'price_amount' => 'integer',
        'x' => 'float',
        'y' => 'float',
        'rotation' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class, 'row_id');
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
