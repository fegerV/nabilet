<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Nabilet\Modules\Content\Domain\PageStatus;
use Nabilet\Modules\Core\Models\Concerns\HasPublicId;

/**
 * Редакционная страница CMS (стратегия SEO, B-2).
 *
 * СХЕМА ТАБЛИЦЫ — `nabilet_core_spec/migrations/002_content.sql:80-95`:
 *
 *   public_id CHAR(26) NOT NULL — ULID в base32, контракт с публичным адресом.
 *   organization_id BIGINT NULL — мультитенантность: общая страница (NULL) или
 *     страница конкретной организации. Уникальность slug'а — составная
 *     `uq_pages_org_slug (organization_id, slug)`, и в MySQL 8.0.13+
 *     `NULL != NULL` для UNIQUE, поэтому общая страница одна на slug.
 *   title VARCHAR(500) NOT NULL — заголовок документа (H1 на странице).
 *   slug VARCHAR(255) NOT NULL — латиницей (как `venues.slug`/`artists.slug`):
 *     адрес публичный, и транслитерация через `Str::slug()` непоследовательна
 *     (`Тюмень→tiumen`, `Брянск→briansk`). Slug пишется руками.
 *   content LONGTEXT NULL — тело статьи (HTML или Markdown, парсер живёт
 *     ниже — в `PageRenderer`). NULL допустим: пустая страница — не ошибка,
 *     она просто вернёт 404, потому что показывать нечего.
 *   status VARCHAR(32) NOT NULL DEFAULT 'draft' — состояние документа.
 *     Только `published` показывается (см. `PageService::forSlug()`).
 *     Значения (`draft`, `published`, `archived`) согласованы с
 *     `EventStatus` для единообразия, хотя на практике у страниц их меньше.
 *   published_at DATETIME(6) NULL — момент публикации. NULL + status='published'
 *     трактуется как «ещё не опубликовано»: так миграция не заставляет
 *     проставлять дату вручную при первой вставке.
 *
 * СВЯЗИ НЕ ОПИСАНЫ. `page_translations` в схеме НЕТ (полиглот не предусмотрен
 * v1), `seo_meta` — полиморфная (entity_type/entity_id) и используется
 * внешне, поэтому own-relation отсюда не нужна. Тестовая фикстура
 * `tests/Support/ContentFixtures.php` собирает то, что страница обязана читать:
 * одна строка `pages` и при желании одна запись `seo_meta` (entity_type='page').
 *
 * `updated_at` НЕ ЗАПИСАН В `pages` SQL: схема объявляет `created_at` и
 * `updated_at`, и `Eloquent` обновляет их сам через `$timestamps = true`. Запрос
 * «когда менялся документ» для sitemap и JSON-LD `dateModified` идёт через
 * `updated_at` обычным способом.
 *
 * ИСПОЛЬЗУЕТСЯ ТОЛЬКО `Page`. `page_translations` в схеме НЕТ (полиглот не
 * предусмотрен v1), `seo_meta` — полиморфная (entity_type/entity_id) и
 * используется внешне, поэтому own-relation отсюда не нужна.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $organization_id
 * @property string $title
 * @property string $slug
 * @property string|null $content
 * @property string $status
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
class Page extends Model
{
    use HasPublicId;

    protected $table = 'pages';

    protected $fillable = [
        'public_id',
        'organization_id',
        'title',
        'slug',
        'content',
        'status',
        'published_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'published_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}