<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Content\Http\Controllers\PageTranslationController;

/*
 * API-маршруты модуля Content — переводы редакционных страниц CMS
 * (контракт openapi.yaml:2426-2468, тег Translations).
 *
 * Файл подключается централизованно в корневом `routes/api.php` (правило
 * проекта: маршрут принадлежит модулю, который его обслуживает —
 * `tools/verify-route-ownership.php`); провайдер модуля маршруты не грузит.
 *
 * ВСЕГО ДВА ПУТИ, А НЕ REST НА lifo `pages`. Админских CRUD-путей страниц в
 * спеке нет: строки `pages` создаёт платформа (NULL = «общая строка»), и
 * админ-путь арендатора ограничен переводами уже существующей страницы.
 * Владельца решает `OrganizationContext::assertCanAccess` по
 * `pages.organization_id` — общая страница (NULL) и чужая отвечают 404
 * одинаково (IDOR).
 */
Route::prefix('pages')->group(function (): void {
    // Витринный список: как `/venues/{venue}/translations` — без auth;
    // неопубликованная страница отвечает 404 (та же пара ключей, что в
    // `PageService::forSlug`), не раскрывая переводы черновика.
    Route::get('/{page}/translations', [PageTranslationController::class, 'index']);

    // Запись перевода: сотрудник организации страницы.
    Route::put('/{page}/translations/{locale}', [PageTranslationController::class, 'update'])
        ->middleware(['auth:api', 'admin']);
});
