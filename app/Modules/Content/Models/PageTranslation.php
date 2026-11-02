<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property string $locale
 * @property string $title
 * @property string $content
 * @property string|null $meta_description
 */
class PageTranslation extends Model
{
    use TenantScoped;
    protected $table = 'page_translations';

    protected $fillable = [
        'page_id',
        'locale',
        'title',
        'content',
        'meta_description',
    ];

    /**
     * Глобальный читающий скоуп (двадцать восьмая модель — см. докблок
     * `EventTranslation`). Запись защищена трейтом (TenantResolver-карта
     * ведёт через `page_id` → `pages`). Читателей в проде нет:
     * `PageService::forSlug` собирает документ из самой строки `pages`,
     * переводы не трогает — скоуп здесь чистый fail-closed ограждением для
     * будущих читателей, обходить в bypass нечего.
     */
    protected static function tenantScopeEnabled(): bool
    {
        return true;
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
