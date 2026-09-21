<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HallSchemaVersion Model
 */
class HallSchemaVersion extends Model
{
    protected $table = 'hall_schema_versions';

    public $timestamps = true;

    protected $fillable = [
        'public_id',
        'hall_id',
        'version',
        'status',
        'width',
        'height',
        'background_url',
        'schema_json',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'schema_json' => 'array',
            'published_at' => 'datetime:Y-m-d H:i:s.u',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
            'updated_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    public function sectors(): HasMany
    {
        return $this->hasMany(Sector::class, 'schema_version_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'schema_version_id');
    }
}
