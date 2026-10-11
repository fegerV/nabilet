<?php

declare(strict_types=1);

use Nabilet\Modules\Seo\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
 * SEO module routes (ТЗ §38).
 *
 * Sitemap endpoints for search engine indexing.
 *
 * ШЕСТЬ КАРТ. Карты площадок, городов, категорий и организаторов появились вместе
 * с посадочными страницами (аудит P-12). Раньше карта площадок была удалена
 * намеренно: она указывала на `route('venues.show')` — маршрут, которого никогда
 * не существовало. Эндпоинт отвечал 500, а если бы маршрут появился, карта
 * объявляла бы страницы, за которыми нет ничего. Карта добавляется ВМЕСТЕ со
 * страницей, а не раньше неё, — и теперь все четыре страницы существуют.
 *
 * Карта категорий — тем же правилом. Категории в проекте пока не заведены, но
 * страница `/category/{slug}` уже отвечает, и карта перечисляет ровно те
 * категории, которые открываются. Карта организаторов наполнена с самого начала:
 * организация создаётся установщиком.
 */

// Sitemap index and section routes.
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemap-events.xml', [SitemapController::class, 'events'])->name('sitemap.events');
Route::get('/sitemap-static.xml', [SitemapController::class, 'static'])->name('sitemap.static');
Route::get('/sitemap-venues.xml', [SitemapController::class, 'venues'])->name('sitemap.venues');
Route::get('/sitemap-cities.xml', [SitemapController::class, 'cities'])->name('sitemap.cities');
Route::get('/sitemap-categories.xml', [SitemapController::class, 'categories'])->name('sitemap.categories');
Route::get('/sitemap-organizers.xml', [SitemapController::class, 'organizers'])->name('sitemap.organizers');
