<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * Hall Model
 */
class Hall extends Model
{
    protected $table = 'halls';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'venue_id',
        'name',
        'description',
        'capacity',
        'width',
        'height',
        'status',
    ];

    /** Конвенция проекта: char(26) public_id NOT NULL → генерим ULID при создании. */
    protected static function booted(): void
    {
        static::creating(function (Hall $hall): void {
            if ($hall->public_id === null) {
                $hall->public_id = \Illuminate\Support\Str::ulid()->toBase32();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function schemaVersions(): HasMany
    {
        return $this->hasMany(HallSchemaVersion::class);
    }

    /**
     * Опубликованная версия схемы зала — та, по которой продаются места.
     *
     * Отношения не было, хотя `HallRepository` его жадно грузил:
     * `with(['currentSchemaVersion'])` в `findByVenue()` и
     * `with(['currentSchemaVersion.sectors.rows.seats'])` в `find()`. Laravel
     * падает на первом же неизвестном отношении, поэтому
     * `GET /venues/{publicId}/halls` отвечал 500 «Call to undefined relationship
     * [currentSchemaVersion] on model [Hall]» — список залов площадки не
     * открывался вообще, а вместе с ним и редактор зала из админки.
     *
     * Опубликованной версия может быть ровно одна: `HallRepository::publishSchemaVersion()`
     * переводит предыдущую в `archived`. Отсюда `hasOne` + сортировка по
     * `version` — на случай, если в базе остался исторический беспорядок.
     */
    public function currentSchemaVersion(): HasOne
    {
        return $this->hasOne(HallSchemaVersion::class)
            ->where('status', 'published')
            ->orderByDesc('version');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }
}
