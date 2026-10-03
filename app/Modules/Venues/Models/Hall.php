<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }
}
