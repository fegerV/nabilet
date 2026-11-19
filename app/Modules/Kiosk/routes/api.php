<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Kiosk\Http\Controllers\Admin\KioskDeviceController;
use Nabilet\Modules\Kiosk\Http\Controllers\KioskController;
use Nabilet\Modules\Kiosk\Http\Middleware\AuthenticateKiosk;

/*
 * Киоск — устройство, а не пользователь. Все его маршруты идут под
 * `AuthenticateKiosk`, который ставит organization_id из токена. Админские
 * маршруты — под обычным `auth:api` + `admin`.
 *
 * Алиас middleware НЕ регистрируется: класс указан прямо, потому что модульные
 * провайдеры не гарантированно бутсятся (см. bootstrap/providers.php).
 */

Route::prefix('kiosk')->middleware(AuthenticateKiosk::class)->group(function (): void {
    Route::post('heartbeat', [KioskController::class, 'heartbeat']);
    Route::get('config', [KioskController::class, 'config']);
    Route::post('update-result', [KioskController::class, 'updateResult']);

    Route::post('sales', [KioskController::class, 'sale']);
    Route::get('sales/{clientSaleId}', [KioskController::class, 'saleStatus']);
});

Route::prefix('admin/kiosks')->middleware(['auth:api', 'admin'])->group(function (): void {
    Route::get('analytics', [KioskDeviceController::class, 'analytics']);
    Route::get('', [KioskDeviceController::class, 'index']);
    Route::post('', [KioskDeviceController::class, 'store']);

    Route::get('{kiosk}', [KioskDeviceController::class, 'show']);
    Route::patch('{kiosk}', [KioskDeviceController::class, 'update']);

    Route::post('{kiosk}/rotate-token', [KioskDeviceController::class, 'rotateToken']);
    Route::post('{kiosk}/revoke', [KioskDeviceController::class, 'revoke']);

    Route::post('{kiosk}/update', [KioskDeviceController::class, 'issueUpdate']);
    Route::post('{kiosk}/update/cancel', [KioskDeviceController::class, 'cancelUpdate']);

    Route::get('{kiosk}/sales', [KioskDeviceController::class, 'sales']);
});
