<?php

declare(strict_types=1);

namespace App\Modules\Seo\Http\Controllers;

use App\Modules\Seo\Services\SitemapService;
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
     */
    public function events(int $page = 1): Response
    {
        $paginator = $this->sitemapService->getEventsSitemap([], $page);
        $xml = $this->sitemapService->generateEventsSitemap($paginator->getCollection());

        return response($xml)
            ->header('Content-Type', 'text/xml')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Serve venues sitemap.
     */
    public function venues(): Response
    {
        $venues = $this->sitemapService->getVenues();
        $xml = $this->sitemapService->generateVenuesSitemap($venues);

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
