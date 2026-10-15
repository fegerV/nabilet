<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Seo\Http\Controllers\LandingController;
use Nabilet\Modules\Seo\Http\Controllers\SitemapController;

/*
 * Web-маршруты модуля Seo — карты сайта и посадочные страницы (аудит P-12):
 * площадка, город, категория, фасет «город × категория», организатор и артист
 * (последний — стратегия SEO, B-1).
 *
 * ПОЧЕМУ В WEB, А НЕ В API. Это человекочитаемые адреса, которые открывают из
 * поисковой выдачи и из письма, и они обязаны отвечать HTML-документом. Файл
 * `routes/api.php` модуля подключён внутри группы с префиксом `/api/v1`, то есть
 * адрес стал бы `/api/v1/city/moskva` — адрес с техническим префиксом в выдаче
 * и в ссылках, который к тому же вернул бы конверт §66 вместо страницы.
 *
 * ПОЧЕМУ ОТДЕЛЬНЫМ ФАЙЛОМ, А НЕ В `routes/web.php`. Правило проекта: маршрут
 * принадлежит модулю, который его обслуживает (`tools/verify-route-ownership.php`).
 * `routes/web.php` — единственный владелец подключения, но не автор маршрутов;
 * так же подключены `Events/routes/web.php` и `Installer/routes/web.php`.
 *
 * ПОРЯДОК РЕГИСТРАЦИИ. Эти маршруты объявлены ДО `Route::fallback()` в
 * `routes/web.php`, иначе брендированная 404 перехватывала бы их: `fallback()`
 * проверяется после всех обычных маршрутов, но только для тех, что уже
 * зарегистрированы на момент обхода.
 *
 * ПОЧЕМУ ФАСЕТ ЖИВЁТ ПОД `/city/`, А НЕ В КОРНЕ. Аудит (§2.4) предлагал
 * `/{city}/{category}`, и от короткого адреса сознательно отказались: маршрут из
 * двух сегментов в корне — это маска, перехватывающая ЛЮБОЙ двухсегментный путь,
 * и первый же будущий `/payment/{id}` или `/install/{step}` молча начнёт
 * отвечать 404. Проект уже платил за это (маска `{any}` против `/api/v1`).
 * Подробнее — в докблоке `LandingPageService::forCityCategory()`.
 *
 * `/city/{slug}/{category}` объявлен ПОСЛЕ `/city/{slug}`: пути различаются
 * числом сегментов и не пересекаются, но правило «частный маршрут выше общего»
 * дешевле соблюсти, чем однажды выяснять, почему адрес отвечает не тем.
 */

/*
 * Карты сайта (ТЗ §38, аудит P-12 и P-10a): /sitemap.xml и шесть разделов.
 *
 * ПОЧЕМУ В WEB, А НЕ В API. Долгое время карты жили по адресу
 * `/api/v1/sitemap.xml`, потому что модуль подключался только из `routes/api.php`.
 * Это работало, но противоречило самому себе: `robots.txt` запрещает `/api/v1/`
 * целиком и делает для карты исключение, то есть файл одновременно запрещает
 * адрес и объявляет его картой сайта. Любая проверка по спецификации и любой
 * отчёт Search Console видели здесь ошибку.
 *
 * Карта сайта — не часть API. Она не версионируется, не требует JSON-конверта
 * и адресуется роботом, а не клиентом. Корневой путь `/sitemap.xml` — адрес,
 * который ищут Яндекс и Google по умолчанию, даже не читая `robots.txt`.
 *
 * ПОЧЕМУ МОЖНО, ХОТЯ РАНЬШЕ БЫЛО НЕЛЬЗЯ. `tools/verify-route-ownership.php`
 * следит не за «модуль живёт в API», а за тем, что каждый
 * `app/Modules/**\/routes/api.php` подключён ровно из `routes/api.php`. Файл
 * `Seo/routes/api.php` удалён вместе с переносом, так что проверке нечего
 * находить; web-маршруты модуля Seo подключались из `routes/web.php` и раньше
 * (посадочные страницы), то есть префикс `/api/v1` ни на что не влиял.
 *
 * ИМЕНА МАРШРУТОВ СОХРАНЕНЫ (`sitemap.index`, `sitemap.events`, …), потому что
 * на них опирается `SitemapService::generateSitemapIndex()`: он строит адреса
 * через `route()`, а не строкой. Достаточно перенести регистрацию — и все
 * ссылки внутри карт становятся корневыми автоматически.
 *
 * СТАРЫЕ АДРЕСА `/api/v1/sitemap*.xml` отвечают 301 на новые. Редирект сделан
 * в nginx, а не здесь: на api-группу навешан `ForceJsonResponse`, поэтому
 * маршрут внутри `/api/v1` отдал бы JSON-конверт вместо редиректа.
 */
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemap-events.xml', [SitemapController::class, 'events'])->name('sitemap.events');
Route::get('/sitemap-static.xml', [SitemapController::class, 'static'])->name('sitemap.static');
Route::get('/sitemap-venues.xml', [SitemapController::class, 'venues'])->name('sitemap.venues');
Route::get('/sitemap-cities.xml', [SitemapController::class, 'cities'])->name('sitemap.cities');
Route::get('/sitemap-categories.xml', [SitemapController::class, 'categories'])->name('sitemap.categories');
Route::get('/sitemap-organizers.xml', [SitemapController::class, 'organizers'])->name('sitemap.organizers');
Route::get('/sitemap-artists.xml', [SitemapController::class, 'artists'])->name('sitemap.artists');
// Карта редакционных страниц (стратегия SEO, B-2): FAQ, «О компании»,
// «Как вернуть билет» и т. п. Контент живёт в `Modules\Content\Page`,
// контроллер карты остаётся в Seo, потому что карта сайта — инфраструктура
// поисковой выдачи и принадлежит тому же модулю, что и остальные карты.
Route::get('/sitemap-pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');

Route::get('/venue/{slug}', [LandingController::class, 'venue'])->name('landing.venue');
Route::get('/city/{slug}', [LandingController::class, 'city'])->name('landing.city');
Route::get('/category/{slug}', [LandingController::class, 'category'])->name('landing.category');
Route::get('/organizer/{slug}', [LandingController::class, 'organizer'])->name('landing.organizer');
// Артист — сущность каталога (стратегия SEO, B-1). Адрес в корне, рядом с
// площадкой и организатором: имя исполнителя — то, что человек вводит в поиск,
// поэтому адрес обязан быть коротким и читаемым, а не вложенным в раздел.
Route::get('/artist/{slug}', [LandingController::class, 'artist'])->name('landing.artist');
Route::get('/city/{slug}/{category}', [LandingController::class, 'cityCategory'])->name('landing.city.category');
