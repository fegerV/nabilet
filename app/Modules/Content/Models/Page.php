<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $public_id
 * @property bool $is_published
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class Page extends Model
{
    protected $table = 'pages';

    protected $fillable = [
        'slug',
        'public_id',
        'is_published',
    ];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(PageTranslation::class);
    }

    public function seoMeta(): HasMany
    {
        return $this->hasMany(SeoMeta::class);
    }
}
