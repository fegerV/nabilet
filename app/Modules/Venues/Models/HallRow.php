<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * HallRow Model
 */
class HallRow extends Model
{
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

        /** Конвенция проекта: char(26) public_id NOT NULL → генерим ULID при создании. */
        protected static function booted(): void
        {
            static::creating(function (HallRow $model): void {
                if ($model->public_id === null) {
                    $model->public_id = Str::ulid()->toBase32();
                }
            });
        }
    }
