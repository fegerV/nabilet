<?php

declare(strict_types=1);

use App\Modules\Seo\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
 * SEO module routes (ТЗ §38).
 *
 * Sitemap endpoints for search engine indexing.
 */

// Sitemap index and section routes
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemap-events.xml', [SitemapController::class, 'events'])->name('sitemap.events');
Route::get('/sitemap-venues.xml', [SitemapController::class, 'venues'])->name('sitemap.venues');
Route::get('/sitemap-static.xml', [SitemapController::class, 'static'])->name('sitemap.static');
