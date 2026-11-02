<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Models;

use Nabilet\Modules\Core\Tenancy\TenantScoped;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $page_id
 * @property int|null $organization_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $content
 * @property string|null $slug
 * @property string|null $seo_title
 * @property string|null $seo_description
 */
class PageTranslation extends Model
{
    use TenantScoped;
    protected $table = 'page_translations';

    /**
     * В схеме таблицы нет `created_at`/`updated_at` (см.
     * `2026_09_20_000200_002_content.php`): перевод — производная строка,
     * её жизненный цикл повторяет страницу, и «когда менялся перевод»
     * контракту не нужен.
     */
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

    /**
     * Глобальный читающий скоуп (двадцать восьмая модель — см. докблок
     * `EventTranslation`). Запись защищена трейтом (TenantResolver-карта
     * ведёт через `page_id` → `pages`). До появления
     * `PageTranslationController` читателей в проде не было:
     * `PageService::forSlug` собирает документ из самой строки `pages`.
     * Интернет-читатель один — публичный список `/api/v1/pages/{page}/translations`
     * в bypass; админский писатель идёт под `auth:api` в скоупе организации.
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
