<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Http\Controllers;

use Nabilet\Modules\Seo\Services\SitemapService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Sitemap controller (ТЗ §38).
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
        return response($this->sitemapService->generateSitemapIndex())
            ->header('Content-Type', 'text/xml')
            ->header('Cache-Control', 'public, max-age=3600');
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

        $paginator = $this->sitemapService->getEventsSitemap([], $page);
        $xml = $this->sitemapService->generateEventsSitemap($paginator->getCollection());

        return response($xml)
            ->header('Content-Type', 'text/xml')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Serve static pages sitemap.
     */
    public function static(): Response
    {
        $xml = $this->sitemapService->generateStaticSitemap();

        return response($xml)
            ->header('Content-Type', 'text/xml')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
