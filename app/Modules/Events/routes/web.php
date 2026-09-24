<?php

declare(strict_types=1);

use Nabilet\Modules\Events\Http\Controllers\EventPageController;
use Illuminate\Support\Facades\Route;

/*
 * Web-маршруты модуля Events — SEO-страницы мероприятия.
 *
 * URL по slug (контракт sitemap: route('events.show', [slug, publicId])).
 * Без trailing publicId — редирект 301 на канонический.
 */
Route::get('/event/{slug}', [EventPageController::class, 'show']);
Route::get('/event/{slug}/{publicId}', [EventPageController::class, 'show'])->name('events.show');