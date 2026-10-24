<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Http\Controllers;

use Nabilet\Core\Http\ContentType;
use Nabilet\Modules\Seo\Services\SitemapService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sitemap controller (ТЗ §38).
 *
 * АДРЕСА. Карты отвечают в корне: `/sitemap.xml` и `/sitemap-{раздел}.xml`
 * (аудит P-10a). Раньше они жили под `/api/v1/`, из-за чего `robots.txt`
 * вынужден был запрещать раздел и тут же объявлять в нём карту. Маршруты
 * зарегистрированы в `app/Modules/Seo/routes/web.php`; старые пути отвечают
 * 301 через nginx, потому что на api-группу навешан `ForceJsonResponse` и
 * редирект изнутри `/api/v1` отдал бы JSON вместо перехода.
 */
class SitemapController
{
    public function __construct(
        private readonly SitemapService $sitemapService
    ) {}

    /**
     * Serve sitemap index.
     */
    public function index(): Response
    {
        return $this->xml($this->sitemapService->cachedSitemapIndex());
    }

    /**
     * Serve events sitemap page.
     *
     * The page number arrives as a query string (`?page=N`), which is the shape
     * `generateSitemapIndex()` already emits. It has to be read from the request
     * explicitly: a bare `int $page = 1` parameter is resolved from ROUTE
     * parameters, so every index entry pointed at the same first page.
     */
    public function events(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));

        return $this->xml($this->sitemapService->cachedEventsSitemap($page));
    }

    /**
     * Serve static pages sitemap.
     */
    public function static(): Response
    {
        return $this->xml($this->sitemapService->cachedStaticSitemap());
    }

    /**
     * Карта площадок (аудит P-12) — `/sitemap-venues.xml`.
     */
    public function venues(): Response
    {
        return $this->xml($this->sitemapService->cachedVenuesSitemap());
    }

    /**
     * Карта городов (аудит P-12) — `/sitemap-cities.xml`.
     */
    public function cities(): Response
    {
        return $this->xml($this->sitemapService->cachedCitiesSitemap());
    }

    /**
     * Карта категорий (аудит P-12) — `/sitemap-categories.xml`.
     */
    public function categories(): Response
    {
        return $this->xml($this->sitemapService->cachedCategoriesSitemap());
    }

    /**
     * Карта организаторов (аудит P-12) — `/sitemap-organizers.xml`.
     */
    public function organizers(): Response
    {
        return $this->xml($this->sitemapService->cachedOrganizersSitemap());
    }

    /**
     * Карта артистов (стратегия SEO, B-1) — `/sitemap-artists.xml`.
     */
    public function artists(): Response
    {
        return $this->xml($this->sitemapService->cachedArtistsSitemap());
    }

    /**
     * Карта редакционных страниц (стратегия SEO, B-2) — `/sitemap-pages.xml`.
     *
     * Сюда попадают только `Page.status = published` с непустым `published_at
     * <= now()` (см. `CatalogService::pages()`). Черновики и страницы с
     * отложенной публикацией карта не выдаёт — это решает проблему «случайно
     * попавший в индекс черновик» системно, без отдельного правила в коде.
     */
    public function pages(): Response
    {
        return $this->xml($this->sitemapService->cachedPagesSitemap());
    }

    /**
     * Единый ответ карты: тип содержимого и часовой HTTP-кеш.
     *
     * Одним методом, потому что раньше заголовки повторялись в каждом действии,
     * и добавление карты означало ещё одну копию. Копия заголовков — это место,
     * где одна карта однажды получила бы `text/html` или забыла `Cache-Control`,
     * и заметить это можно было бы только по поведению краулера.
     *
     * `text/xml`, а не `application/xml`: первое — исторически ожидаемый
     * краулерами тип для карт, второе тоже принимается, но менять работающее
     * значение ради формальной точности здесь незачем.
     */
    private function xml(string $body): Response
    {
        return response($body)
            ->header('Content-Type', ContentType::XML)
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
