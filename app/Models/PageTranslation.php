<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $content
 * @property string|null $slug
 * @property string|null $seo_title
 * @property string|null $seo_description
 */
class PageTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'page_id',
        'locale',
        'title',
        'content',
        'slug',
        'seo_title',
        'seo_description',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
