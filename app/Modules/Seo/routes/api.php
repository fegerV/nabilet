<?php

declare(strict_types=1);

use Nabilet\Modules\Seo\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
 * SEO module routes (ТЗ §38).
 *
 * Sitemap endpoints for search engine indexing.
 */

// Sitemap index and section routes.
// No `sitemap-venues.xml`: there is no public venue page to point at. See the
// note in `SitemapService` — the endpoint 500'd on an undefined route.
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemap-events.xml', [SitemapController::class, 'events'])->name('sitemap.events');
Route::get('/sitemap-static.xml', [SitemapController::class, 'static'])->name('sitemap.static');
