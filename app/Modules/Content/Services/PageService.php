<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Services;

use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Content\Domain\PageDocument;
use Nabilet\Modules\Content\Domain\PageStatus;
use Nabilet\Modules\Content\Models\Page;

/**
 * Сборка редакционной страницы CMS (стратегия SEO, B-2).
 *
 * ЗАЧЕМ ОТДЕЛЬНО ОТ КОНТРОЛЛЕРА. Контроллер знает про HTTP и 404, но не
 * должен решать, что считать «опубликованной» страницей или откуда брать
 * описание: это контракт данных, а не контракт ответа. Если однажды появится
 * «превью» (страница с `?preview=token`), проверка прав ляжет именно на
 * сервис, а контроллер останется тонкой обёрткой.
 *
 * ЗАЧЕМ ОТДЕЛЬНО ОТ `Seo\Services\LandingPageService`. Тот собирает посадочную
 * страницу со списком событий и пагинацией, эта собирает ОДИН ДОКУМЕНТ с
 * контентом. Объединять их — значит размыть `LandingPage` (список, related,
 * пагинация) и `PageDocument` (один текст, без списков), а правки формулировок
 * посадочных страниц по SEO-причинам тогда трогали бы редакционный документ.
 *
 * ЧТО СЧИТАЕТСЯ ОПУБЛИКОВАННЫМ. `status='published'` И `published_at <= now()`
 * (с `NULL` как «нет даты — не опубликовано»). Это та же пара ключей, по
 * которой `SitemapService::getEventsSitemap()` решает, что индексировать:
 * расхождение между картой и страницей — это 404 из sitemap'а.
 *
 * ОПИСАНИЕ — ПЕРВЫЕ ~160 СИМЕТОВ КОНТЕНТА, ОЧИЩЕННЫЕ ОТ HTML. Тег `<meta
 * name="description">` показывается в выдаче вместо первых строк документа,
 * если описание не вынесено в отдельное поле. У `pages` такого поля нет —
 * решение принято осознанно при проектировании схемы (см.
 * `nabilet_core_spec/migrations/002_content.sql`), — и обрезать HTML надо
 * именно здесь, потому что именно здесь есть доступ к тексту. Рендеру
 * остаётся только вставить готовое.
 *
 * `robots` НАСЛЕДУЕТСЯ ИЗ `pages.robots`, ЕСЛИ ЗАПОЛНЕН. В `pages` такой
 * колонки нет (`pages` — `public_id/organization_id/title/slug/content/status/
 * published_at`), и явные `noindex` для редакционных страниц живут на уровне
 * публикации: страница либо показана, либо не показана. Здесь поле
 * оставлено на будущее — когда у страницы появится колонка robots или
 * полиморфная `seo_meta` с переопределением.
 *
 * `organizationName` ТЯНЕТСЯ ОТДЕЛЬНЫМ ЗАПРОСОМ ТОЛЬКО ПРИ НАЛИЧИИ
 * `organization_id`. Делать JOIN к `organizations` в основном запросе — это
 * читать имя для каждой опубликованной страницы, а нужно оно ровно для одной
 * (текущей). Отдельный запрос дешевле JOIN'а и проще по поведению.
 */
class PageService
{
    /**
     * Опубликованная страница по slug'у, либо `null` для несуществующей или
     * черновика.
     *
     * Контроллер трактует `null` как 404 — см. `PageController::show()`.
     */
    public function forSlug(string $slug): ?PageDocument
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('status', PageStatus::PUBLISHED)
            ->where(function ($query): void {
                // `published_at` NULL + `status='published'` трактуется как
                // «ещё не опубликовано»: миграция не заставляет проставлять
                // дату вручную при первой вставке.
                $query->whereNotNull('published_at')
                    ->where('published_at', '<=', now());
            })
            ->first();

        if ($page === null) {
            return null;
        }

        return $this->toDocument($page);
    }

    /**
     * Превратить модель в чистый Domain-объект.
     *
     * Слой сервиса — единственное место, где граница между ORM и Domain
     * проходит. Если бы рендер читал модель напрямую, он бы зависел от
     * Eloquent и терял чистоту, а тест рендера требовал бы БД.
     */
    private function toDocument(Page $page): PageDocument
    {
        $organizationName = $page->organization_id === null
            ? null
            : DB::table('organizations')
                ->where('id', (int) $page->organization_id)
                ->value('name');

        return new PageDocument(
            slug: (string) $page->slug,
            title: (string) $page->title,
            description: $this->descriptionFrom((string) ($page->content ?? '')),
            contentHtml: (string) ($page->content ?? ''),
            publishedAt: $page->published_at?->toIso8601String(),
            updatedAt: $page->updated_at?->toIso8601String(),
            robots: null,
            organizationName: $organizationName === null ? null : (string) $organizationName,
        );
    }

    /**
     * Описание из первых ~160 символов контента, очищенное от HTML-разметки.
     *
     * Длина подобрана под типичный лимит сниппета Яндекса (~160 символов) и
     * под `<meta name="description">` (~155–160). Больше — описание режется
     * поисковиком по границе слова, и в сниппет уходит обрывок фразы.
     *
     * HTML СНИМАЕТСЯ `strip_tags` + СЖАТИЕ ПРОБЕЛОВ. Содержимое `pages.content`
     * хранится как LONGTEXT и администратор может вставить туда HTML или
     * Markdown; сейчас в БД лежит HTML (визуальный редактор), и теги в
     * описании дали бы «<p>Концерт в Москве…</p>» в выдаче. `strip_tags`
     * оставляет текст, `preg_replace('/\s+/', ' ', …)` убирает переводы строк
     * и двойные пробелы от бывшей разметки.
     *
     * `mb_substr` ВМЕСТО `substr` — заголовок на русском, и обрез по байтам
     * мог рухнуть посреди многобайтового символа и уронить весь документ
     * «неожиданным» `<meta name="description" content="…">` без закрывающей
     * кавычки.
     */
    private function descriptionFrom(string $content): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($content)) ?? '');

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= 160) {
            return $text;
        }

        return mb_substr($text, 0, 160) . '…';
    }
}