<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Webhooks\Http\Controllers\WebhookController;
use Nabilet\Modules\Webhooks\Http\Controllers\WebhookSubscriptionController;

/*
 * Webhooks Module API Routes
 * These routes are public - signature verification happens in controller
 */

// Приём уведомлений платёжных провайдеров. Один URI — один обработчик:
//   - канонический путь из `docs/openapi.yaml`;
//   - исторический алиас, на который завязаны уже настроенные кабинеты
//     провайдеров и тесты.
// Оба ведут в `WebhookController::payment`: у него конверт §66 с `request_id`,
// а прежний `PaymentController::webhook` отвечал без него. Дублировать URI
// нельзя — Laravel оставляет первый зарегистрированный маршрут, а второй
// становится недостижимым.
Route::post('/payments/webhooks/{provider}', [WebhookController::class, 'payment'])
    ->name('webhooks.payment.canonical');
Route::post('/webhooks/payment/{provider}', [WebhookController::class, 'payment'])
    ->name('webhooks.payment');

// Generic webhook endpoint with signature verification
Route::post('/webhooks/{type}', [WebhookController::class, 'handle'])->name('webhooks.handle');

// Управление ИСХОДЯЩИМИ вебхуками (подписками). Отдельный префикс, чтобы не
// смешивать с точками приёма: inbound-маршруты аутентифицируются подписью
// провайдера и открыты, эти — только для администратора организации.
Route::prefix('webhook-subscriptions')->middleware(['auth:api', 'admin'])->group(function () {
    Route::get('/', [WebhookSubscriptionController::class, 'index']);
    Route::post('/', [WebhookSubscriptionController::class, 'store']);
    Route::put('/{webhook}', [WebhookSubscriptionController::class, 'update'])->whereNumber('webhook');
    Route::delete('/{webhook}', [WebhookSubscriptionController::class, 'destroy'])->whereNumber('webhook');
    Route::get('/{webhook}/deliveries', [WebhookSubscriptionController::class, 'deliveries'])->whereNumber('webhook');
});

// Здесь был второй, ПОЛНОСТЬЮ идентичный маршрут с именем `webhooks.handle2`:
// тот же метод, тот же путь. Он назывался «алиасом», но алиасом не был —
// Laravel сопоставляет первый зарегистрированный маршрут, так что второй был
// недостижим, а `route('webhooks.handle2')` генерировал тот же URL, что и
// `route('webhooks.handle')`. Удалён: дубль в таблице маршрутов ещё и путал
// `php artisan route:list`.
