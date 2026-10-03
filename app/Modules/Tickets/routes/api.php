<?php

declare(strict_types=1);

use Nabilet\Modules\Tickets\Http\Controllers\TicketController;
use Nabilet\Modules\Tickets\Http\Controllers\CheckinController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
Route::prefix('tickets')->group(function () {
    Route::get('/', [TicketController::class, 'index']);
    Route::get('/{ticket}', [TicketController::class, 'show']);
    Route::get('/{ticket}/qr', [TicketController::class, 'qrCode']);
    Route::get('/{ticket}/history', [TicketController::class, 'history']);
    
    Route::post('/checkin/scan', [CheckinController::class, 'scan']);
    Route::post('/checkin/verify', [CheckinController::class, 'verify']);
});
