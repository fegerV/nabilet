<?php

declare(strict_types=1);

namespace NabileT\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $alt_text
 * @property \Carbon\CarbonImmutable $created_at
 */
class MediaAsset extends Model
{
    protected $table = 'media_assets';

    protected $fillable = [
        'name',
        'disk',
        'path',
        'mime_type',
        'size_bytes',
        'alt_text',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function links(): HasMany
    {
        return $this->hasMany(MediaLink::class);
    }
}
