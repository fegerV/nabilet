<?php

declare(strict_types=1);

namespace Nabilet\Modules\Seo\Services;

use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Domain\EventStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Sitemap generation service (ТЗ §38).
 *
 * Generates XML sitemaps for search engines, split by content type to stay
 * under the 50,000 URL / 50 MB limits. Only published, visible content is
 * included — drafts and scheduled events are excluded.
 *
 * There is deliberately no venues sitemap. One existed and pointed every venue
 * at `route('venues.show')`, a route that has never been defined — so the
 * endpoint answered 500 and, had the route existed, would have advertised pages
 * with no component behind them. A venue page has to exist before it can be
 * indexed; the sitemap should be added back together with it, not before.
 */
class SitemapService
{
    /** Maximum URLs per sitemap file (Google limit) */
    private const MAX_URLS_PER_SITEMAP = 50_000;

    /** Cache TTL in seconds (1 hour) */
    private const CACHE_TTL = 3600;

    /** Default priority for events */
    private const DEFAULT_EVENT_PRIORITY = 0.8;

    /** High priority for recently published events */
    private const HIGH_EVENT_PRIORITY = 0.9;

    /**
     * @param array<string, mixed> $filters
     */
    public function getEventsSitemap(array $filters = [], int $page = 1): LengthAwarePaginator
    {
        $perPage = self::MAX_URLS_PER_SITEMAP;

        return Event::query()
            ->whereIn('status', EventStatus::publiclyVisible())
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('published_at', 'desc')
            ->paginate($perPage, ['public_id', 'slug', 'updated_at', 'published_at'], 'page', $page);
    }

    /**
     * Static pages that should be indexed.
     *
     * Only pages that actually exist. The storefront is a hash-routed SPA, so the
     * catalog is the one addressable static page — `about`, `privacy` and `terms`
     * have neither a server route nor a component, and calling `route()` on them
     * threw `RouteNotFoundException`, which is what made this endpoint answer 500.
     * Add them here together with the pages themselves, never before.
     *
     * @return array<int, array{url: string, lastmod: ?string, changefreq: string, priority: float}>
     */
    public function getStaticPages(): array
    {
        return [
            [
                'url' => url('/'),
                'lastmod' => now()->toIso8601String(),
                'changefreq' => 'daily',
                'priority' => 1.0,
            ],
        ];
    }

    /**
     * Generate sitemap index pointing to individual sitemaps.
     */
    public function generateSitemapIndex(): string
    {
        $eventCount = Event::query()
            ->whereIn('status', EventStatus::publiclyVisible())
            ->whereNotNull('published_at')
            ->count();

        $sitemapCount = (int) ceil($eventCount / self::MAX_URLS_PER_SITEMAP);

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $sitemapIndex = $xml->createElement('sitemapindex');
        $sitemapIndex->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        // Events sitemaps
        for ($i = 1; $i <= max(1, $sitemapCount); $i++) {
            $sitemap = $xml->createElement('sitemap');
            $loc = $xml->createElement('loc', route('sitemap.events', ['page' => $i]));
            $lastmod = $xml->createElement('lastmod', now()->toIso8601String());

            $sitemap->appendChild($loc);
            $sitemap->appendChild($lastmod);
            $sitemapIndex->appendChild($sitemap);
        }

        // Static pages sitemap
        $staticSitemap = $xml->createElement('sitemap');
        $staticLoc = $xml->createElement('loc', route('sitemap.static'));
        $staticLastmod = $xml->createElement('lastmod', now()->toIso8601String());
        $staticSitemap->appendChild($staticLoc);
        $staticSitemap->appendChild($staticLastmod);
        $sitemapIndex->appendChild($staticSitemap);

        $xml->appendChild($sitemapIndex);

        return $xml->saveXML();
    }

    /**
     * Generate XML for events sitemap.
     *
     * @param Collection<int, Event> $events
     */
    public function generateEventsSitemap(Collection $events): string
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $urlset = $xml->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($events as $event) {
            $url = $xml->createElement('url');

            $loc = $xml->createElement(
                'loc',
                $this->getCanonicalUrl($event)
            );

            $lastmod = $xml->createElement(
                'lastmod',
                ($event->updated_at ?? $event->published_at)?->toIso8601String() ?? now()->toIso8601String()
            );

            $changefreq = $xml->createElement('changefreq', 'weekly');

            // Priority based on status
            $priorityValue = $event->status === EventStatus::PUBLISHED 
                ? self::HIGH_EVENT_PRIORITY 
                : self::DEFAULT_EVENT_PRIORITY;
            $priority = $xml->createElement('priority', (string) $priorityValue);

            $url->appendChild($loc);
            $url->appendChild($lastmod);
            $url->appendChild($changefreq);
            $url->appendChild($priority);
            $urlset->appendChild($url);
        }

        $xml->appendChild($urlset);

        return $xml->saveXML();
    }

    /**
     * Get canonical URL for an event.
     */
    private function getCanonicalUrl(Event $event): string
    {
        // Use explicit canonical_url if set
        if (!empty($event->canonical_url)) {
            return $event->canonical_url;
        }

        return route('events.show', [
            'slug' => $event->slug,
            'publicId' => $event->public_id,
        ]);
    }

    /**
     * Generate XML for static pages sitemap.
     */
    public function generateStaticSitemap(): string
    {
        $pages = $this->getStaticPages();

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $urlset = $xml->createElement('urlset');
        $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($pages as $page) {
            $url = $xml->createElement('url');

            $loc = $xml->createElement('loc', $page['url']);
            $lastmod = $xml->createElement('lastmod', $page['lastmod']);
            $changefreq = $xml->createElement('changefreq', $page['changefreq']);
            $priority = $xml->createElement('priority', (string) $page['priority']);

            $url->appendChild($loc);
            $url->appendChild($lastmod);
            $url->appendChild($changefreq);
            $url->appendChild($priority);
            $urlset->appendChild($url);
        }

        $xml->appendChild($urlset);

        return $xml->saveXML();
    }
}
