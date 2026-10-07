<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Notifications\Http\Controllers\MailSettingsController;
use Nabilet\Modules\Notifications\Http\Controllers\NotificationTemplateController;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
//
// Редактирование шаблонов писем — администраторский раздел. `admin` поверх
// `auth:api`: шаблон определяет письмо, которое получит каждый покупатель.
Route::prefix('notification-templates')->middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/', [NotificationTemplateController::class, 'index']);
    Route::get('/{template}', [NotificationTemplateController::class, 'show'])->whereNumber('template');
    Route::put('/{template}', [NotificationTemplateController::class, 'update'])->whereNumber('template');
    Route::post('/{template}/preview', [NotificationTemplateController::class, 'preview'])->whereNumber('template');
});

// SMTP-настройки в админке: без них почту нельзя включить без правки .env и
// перезапуска, что на шаред-хостинге недоступно владельцу магазина.
Route::prefix('admin/mail')->middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/settings', [MailSettingsController::class, 'show']);
    Route::put('/settings', [MailSettingsController::class, 'update']);
    Route::post('/test', [MailSettingsController::class, 'sendTest']);
});
