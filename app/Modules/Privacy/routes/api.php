<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Privacy\Http\Controllers\AdminPrivacyRequestController;
use Nabilet\Modules\Privacy\Http\Controllers\PrivacyRequestController;

// Mounted at /api/v1 — do not repeat the version segment.
//
// Права субъекта ПДн (152-ФЗ). ПУТИ СОЗНАТЕЛЬНО НЕ В СПЕКЕ (openapi.yaml):
// как и newsletter-маршруты, модуль расширяет контракт в приложении, а
// `tools/verify-live.php` требует только «каждый путь спеки отдаётся» (94/94) —
// обратного условия «каждый маршрут в спеке» нет.

/*
 * Публичная часть: подать обращение и отслеживать его по public_id.
 *
 * Гость подаёт заявление так же свободно, как покупает билет, — без входа.
 * `throttle:10,1` держит автогенерацию мусорных обращений; трекинг прощает
 * до 60 в минуту — ссылка «проверить статус» идёт из письма и часто
 * перечитывается.
 */
Route::post('privacy-requests', [PrivacyRequestController::class, 'store'])->middleware('throttle:10,1');
Route::get('privacy-requests/{publicId}', [PrivacyRequestController::class, 'show'])
    ->where('publicId', '[A-Za-z0-9]+')
    ->middleware('throttle:60,1');

/*
 * Обработка оператором. `admin` поверх `auth:api` — роль из `user_roles`
 * (см. `VenueController::destroy`), НЕ колонка users.
 *
 * Адресация только по public_id (`[A-Za-z0-9]+` отсекает `id` и мусор
 * до 404): числовой инкрементальный идентификатор наружу не отдаётся.
 */
Route::prefix('admin/privacy-requests')->middleware(['auth:api', 'admin'])->group(function (): void {
    Route::get('/', [AdminPrivacyRequestController::class, 'index']);
    Route::get('/{publicId}', [AdminPrivacyRequestController::class, 'show'])
        ->where('publicId', '[A-Za-z0-9]+');
    Route::patch('/{publicId}', [AdminPrivacyRequestController::class, 'update'])
        ->where('publicId', '[A-Za-z0-9]+');
});
