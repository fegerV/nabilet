<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Media\Http\Controllers\MediaController;

// Подключается централизованно из routes/api.php — префикс /api/v1 уже задан
// глобально в bootstrap/app.php. Повторять сегмент версии здесь нельзя: это дало
// бы /api/v1/v1/media.
//
// `whereNumber('media')` — не косметика. Неявная привязка модели приводит путь к
// int, поэтому `/media/1abc` молча открывал файл 1: не тот ресурс, что
// запрошен. С ограничением такой путь даёт 404. Ровно эта же ловушка уже
// случалась в модуле Tickets.
Route::prefix('media')->group(function (): void {
    // Чтение: контракт требует `bearerAuth` на всех пяти операциях, и здесь он
    // есть. Список и карточка файла доступны любому сотруднику с сессией.
    Route::middleware(['auth:api'])->group(function (): void {
        Route::get('/', [MediaController::class, 'index']);
        Route::get('/{media}', [MediaController::class, 'show'])->whereNumber('media');
    });

    // Запись: дополнительно `admin`. Контракт не запрещает более строгий
    // уровень, а файл — это афиша, план зала или логотип, то есть то, что
    // видит каждый покупатель. Витрина ничего не загружает, она только читает.
    Route::middleware(['auth:api', 'admin'])->group(function (): void {
        Route::post('/', [MediaController::class, 'store']);
        // PUT и PATCH — один обработчик: контракт объявляет только PATCH, но
        // админский клиент исторически шлёт PUT, и отвечать на него 405 значило
        // бы ломать клиент ради формальности.
        Route::match(['put', 'patch'], '/{media}', [MediaController::class, 'update'])->whereNumber('media');
        Route::delete('/{media}', [MediaController::class, 'destroy'])->whereNumber('media');
    });
});
