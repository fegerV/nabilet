<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Seo\Http\Controllers\LandingController;

/*
 * Web-маршруты модуля Seo — посадочные страницы (аудит P-12): площадка, город,
 * категория, фасет «город × категория» и организатор.
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

Route::get('/venue/{slug}', [LandingController::class, 'venue'])->name('landing.venue');
Route::get('/city/{slug}', [LandingController::class, 'city'])->name('landing.city');
Route::get('/category/{slug}', [LandingController::class, 'category'])->name('landing.category');
Route::get('/organizer/{slug}', [LandingController::class, 'organizer'])->name('landing.organizer');
Route::get('/city/{slug}/{category}', [LandingController::class, 'cityCategory'])->name('landing.city.category');
