<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $media_asset_id
 * @property string $entity_type
 * @property int $entity_id
 * @property string $role
 * @property int $position
 * @property \Carbon\Carbon $created_at
 */
class MediaLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'media_asset_id',
        'entity_type',
        'entity_id',
        'role',
        'position',
        'created_at',
    ];

    protected $casts = [
        'position' => 'integer',
        'created_at' => 'datetime',
    ];

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
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
