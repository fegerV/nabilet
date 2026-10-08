<?php

declare(strict_types=1);

use Nabilet\Modules\Events\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

// The global apiPrefix in bootstrap/app.php already mounts this file at /api/v1.
// Repeating a version segment here produced /api/v1/v1/events, so every route in
// this file was unreachable at the path the spec declares (spec: /api/v1/events).
//
// `whereNumber('event')` — не косметика. Неявная привязка модели приводит путь к
// int, поэтому `/events/1abc` молча открывал событие 1: не тот ресурс, что
// запрошен. С ограничением такой путь даёт 404. Спек объявляет `EventIdPath`
// как `type: string` (задуман `public_id`); перевод всех ресурсов на `public_id`
// — отдельное решение с правкой фронта, здесь выбран fail-closed минимум.
Route::prefix('events')->group(function () {
    Route::get('/', [EventController::class, 'index']);
    Route::get('/by-slug/{slug}', [EventController::class, 'showBySlug']);
    Route::post('/', [EventController::class, 'store'])->middleware(['auth:api', 'admin']);
    Route::get('/{event}', [EventController::class, 'show'])->whereNumber('event');
    Route::put('/{event}', [EventController::class, 'update'])->middleware(['auth:api', 'admin'])->whereNumber('event');
    Route::patch('/{event}', [EventController::class, 'update'])->middleware(['auth:api', 'admin'])->whereNumber('event');
    Route::delete('/{event}', [EventController::class, 'destroy'])->middleware(['auth:api', 'admin'])->whereNumber('event');

    // ── Публикация и отмена ──────────────────────────────────────────────
    //
    // Отдельные эндпоинты, а не `status` в теле `PUT`. Причина — проверки:
    // `EventPublicationPolicy` отказывает событию без сеансов (нет даты, нет
    // цены, нет кнопки «купить»). Обходной путь через `PUT /events/{id}` со
    // `status = 'published'` таких страниц не создаёт, но и не мешает им
    // появиться: он не запускает НИ ОДНОЙ проверки готовности.
    //
    // Глагол POST, а не PATCH: это не правка поля, а переход состояния.
    // Повторный вызов идемпотентен — политика возвращает `no_change`
    // и ничего не пишет (см. `EventPublicationService::publish()`).
    Route::post('/{event}/publish', [EventController::class, 'publish'])
        ->middleware(['auth:api', 'admin'])
        ->whereNumber('event');
    Route::post('/{event}/cancel', [EventController::class, 'cancel'])
        ->middleware(['auth:api', 'admin'])
        ->whereNumber('event');
});
