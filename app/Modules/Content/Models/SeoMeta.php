<?php

declare(strict_types=1);

namespace NabileT\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $meta_title
 * @property string $meta_description
 * @property string|null $og_image
 * @property string|null $canonical_url
 * @property int|null $page_id
 * @property string|null $subject_type
 * @property int|null $subject_id
 */
class SeoMeta extends Model
{
    protected $table = 'seo_meta';

    protected $fillable = [
        'meta_title',
        'meta_description',
        'og_image',
        'canonical_url',
        'page_id',
        'subject_type',
        'subject_id',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
