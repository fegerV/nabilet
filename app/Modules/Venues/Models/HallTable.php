<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * HallTable Model
 *
 * Банкетный стол — геометрия стола внутри сектора `shape: 'table'`. Места за
 * столом продаются как обычные места (кольцо в `seats`), а сама строка
 * `hall_tables` нужна, чтобы стол существовал в БД как объект: по ней витрина
 * рисует круглый стол, не разбирая JSON-payload схемы.
 *
 * Раньше модель была нерабочей: `hall_tables.public_id` объявлен NOT NULL без
 * дефолта, а генерации ULID (как в Sector/Hall/StandingZone) здесь не было —
 * любой `HallTable::create()` падал с «Field 'public_id' doesn't have a default
 * value». Именно поэтому таблица оставалась пустой во всех базах.
 */
class HallTable extends Model
{
    protected $table = 'hall_tables';

    public $timestamps = true;

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

    protected function casts(): array
    {
        return [
            'x' => 'decimal:3',
            'y' => 'decimal:3',
            'width' => 'decimal:3',
            'height' => 'decimal:3',
            'rotation' => 'decimal:3',
            'capacity' => 'integer',
            'metadata_json' => 'array',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    /** Конвенция проекта: char(26) public_id NOT NULL → генерим ULID при создании. */
    protected static function booted(): void
    {
        static::creating(function (HallTable $model): void {
            if ($model->public_id === null) {
                $model->public_id = Str::ulid()->toBase32();
            }
        });
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }
}
