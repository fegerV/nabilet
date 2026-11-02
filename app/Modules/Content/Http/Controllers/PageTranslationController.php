<?php

declare(strict_types=1);

namespace Nabilet\Modules\Content\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Content\Models\Page;
use Nabilet\Modules\Content\Models\PageTranslation;

/**
 * Переводы редакционной страницы CMS (контракт openapi.yaml:2426-2468,
 * тег Translations).
 *
 * `GET /api/v1/pages/{page}/translations` — витринный список, без auth.
 * `PUT /api/v1/pages/{page}/translations/{locale}` — upsert под `auth:api` +
 * `admin`. Полосатый контракт как у площадок: гость читает опубликованное,
 * сотрудник организации правит.
 *
 * ЧТО ДАЁТ SLUG В ПЕРЕВОДЕ. Колонка `page_translations.slug` +
 * `idx_page_translations_locale_slug` — зарезервированное место локализованных
 * адресов: локализованный slug делает `/{locale}/pages/...` разрешимыми, когда
 * такой роутер появится. Сам реестр адресов по-прежнему ведёт основной slug
 * строки `pages` (`PageService::forSlug` переводы не трогает).
 *
 * ПОЧЕМУ НЕ ROUTE BINDING. У `Page` нет `getRouteKeyName` (адресация по
 * integer id вскрывала бы перебор), а скоупа у модели тоже нет — `pages` одна
 * из четырёх nullable-исключений скоупа (NULL = «общая строка» платформы,
 * создаваемая вручную, а не из админки арендатора). Поэтому оба пути делают
 * lookup по unguessable `public_id` руками — как `VenueController::translations`.
 */
class PageTranslationController extends Controller
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    /**
     * GET /api/v1/pages/{page}/translations — витринный список переводов.
     *
     * Видимость страницы решает та же пара ключей, что и `PageService::forSlug`:
     * `status='published'` и `published_at <= now()`. Переводы неопубликованной
     * страницы неотличимы от переводов несуществующей (404) — иначе публичный
     * список стал бы окном в ещё не опубликованный контент.
     *
     * Читающий скоуп переводов (двадцать восьмая модель) исполняется в bypass:
     * у гостя контекста нет, а адрес страницы решает lookup по `public_id`
     * самой страницы.
     */
    public function index(string $page): JsonResponse
    {
        $context = $this->context;

        $pageId = $this->publishedPageIdOrFail($page);

        /** @var list<PageTranslation> $rows */
        $rows = $context->withoutScope(
            fn (): array => PageTranslation::query()->where('page_id', $pageId)->orderBy('locale')->get()->all()
        );

        return response()->json(['data' => $rows]);
    }

    /**
     * PUT /api/v1/pages/{page}/translations/{locale} — создать или заменить
     * перевод страницы: «upsert» на паре `uq_page_translations (page_id, locale)`.
     *
     * ВЛАДЕЛЕЦ. `pages.organization_id` nullable, но «общие» страницы
     * (NULL) не принадлежат ни одной организации — их пишет платформа, и
     * админ-путь им закрыт: `assertCanAccess(null, …)` отвечает 404, той же
     * реакцией, что и чужой `organization_id` (IDOR: чужая страница
     * неотличима от несуществующей).
     *
     * $locale нормализуется в нижний регистр — MySQL сравнивает VARCHAR в
     * `*_ci` collation без учёта регистра, поэтому «RU» и «ru» заняли бы
     * одно место в `uq_page_translations`, оставаясь двумя ключами для
     * клиента.
     *
     * @return JsonResponse 200 — запись есть после вызова (создана или заменена)
     */
    public function update(Request $request, string $page, string $locale): JsonResponse
    {
        $locale = $this->normalizeLocale($locale);

        $data = $request->validate([
            // Max lengths follow the columns: title VARCHAR(500), slug
            // VARCHAR(255), seo_title VARCHAR(500), seo_description
            // VARCHAR(1000), content LONGTEXT.
            'title' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'slug' => ['nullable', 'string', 'max:255'],
            'seo_title' => ['nullable', 'string', 'max:500'],
            'seo_description' => ['nullable', 'string', 'max:1000'],
        ]);

        // Replace, а не patch: «Create or replace» в контракте означает, что PUT
        // фиксирует ПОЛНЫЙ образ перевода — поле, не присланное в запросе,
        // стирается (nullable-колонка), а не остаётся со старым значением.
        $data = array_merge(['title' => null, 'content' => null, 'slug' => null, 'seo_title' => null, 'seo_description' => null], $data);

        /** @var Page|null $model */
        $model = $this->context->withoutScope(
            fn (): ?Page => Page::query()->where('public_id', $page)->first()
        );

        if ($model === null) {
            throw new NotFoundError('Page', $page);
        }

        // Страница без организации не редактируется через админ-путь; чужая —
        // неотличима от неё (404).
        $this->context->assertCanAccess($model->organization_id, 'Page', $page);

        if (isset($data['slug'])) {
            $this->assertSlugAvailable($data['slug'], $locale, (int) $model->id);
        }

        $attributes = ['page_id' => (int) $model->id, 'locale' => $locale];

        // гонку двух PUT на одну пару (page, locale) разруливает сам фреймворк:
        // `updateOrCreate` идёт через `firstOrCreate` → `createOrFirst`, который
        // ловит нарушение `uq_page_translations` (с savepoint'ом) и возвращает
        // строку первого писателя.
        $translation = PageTranslation::query()->updateOrCreate($attributes, $data);

        return response()->json(['data' => $translation]);
    }

    /**
     * Идентификатор ОПУБЛИКОВАННОЙ страницы по `public_id`, иначе 404.
     *
     * @return int id строки `pages`
     */
    private function publishedPageIdOrFail(string $publicId): int
    {
        $id = $this->context->withoutScope(
            fn (): ?int => DB::table('pages')
                ->where('public_id', $publicId)
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->value('id')
        );

        if ($id === null) {
            throw new NotFoundError('Page', $publicId);
        }

        return (int) $id;
    }

    /**
     * Локализованный адрес должен быть разрешим однозначно: в одном языке
     * не может быть двух страниц с одним slug. `uq` в схеме нет (пара
     * `uq_page_translations` защищает только (page, locale)), поэтому
     * uniqueness slug'а в рамках locale проверяется запросом по
     * `idx_page_translations_locale_slug` — 422, а не 500 на конкуренции и
     * не молчаливая двусмысленность URL.
     *
     * Проверка глобальна (чужие страницы тоже учитываются): адресное
     * пространство `/{locale}/pages/...` общее для всех, а slug — публичный
     * URL, и ответ «занят» ничего не раскрывает, кроме того, что и так видно
     * в публичном списке.
     */
    private function assertSlugAvailable(string $slug, string $locale, int $pageId): void
    {
        $taken = $this->context->withoutScope(
            fn (): bool => PageTranslation::query()
                ->where('locale', $locale)
                ->where('slug', $slug)
                ->where('page_id', '!=', $pageId)
                ->exists()
        );

        if ($taken) {
            throw new ValidationError([
                'slug' => ['This slug is already used by another page in this locale.'],
            ]);
        }
    }

    /**
     * Канонический ключ перевода: нижний регистр, `2-3` буквы языка и до
     * двух уточнений через дефис (`ru`, `en`, `pt-br`, `zh-hans-cn` длина до
     * 10 — граница колонки VARCHAR(10)).
     *
     * @return string нормализованный locale
     */
    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(trim($locale));

        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8}){0,2}$/', $normalized) !== 1) {
            throw new ValidationError([
                'locale' => ['The locale must be a language tag like "ru", "en" or "pt-br".'],
            ]);
        }

        return $normalized;
    }
}
