<?php

declare(strict_types=1);

namespace NabileT\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $media_asset_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $caption
 */
class MediaLink extends Model
{
    protected $table = 'media_links';

    protected $fillable = [
        'media_asset_id',
        'subject_type',
        'subject_id',
        'caption',
    ];

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
