<?php

declare(strict_types=1);

use App\Modules\Tickets\Http\Controllers\TicketController;
use App\Modules\Tickets\Http\Controllers\CheckinController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/tickets')->group(function () {
    Route::get('/', [TicketController::class, 'index']);
    Route::get('/{ticket}', [TicketController::class, 'show']);
    Route::get('/{ticket}/qr', [TicketController::class, 'qrCode']);
    Route::get('/{ticket}/history', [TicketController::class, 'history']);
    
    Route::post('/checkin/scan', [CheckinController::class, 'scan']);
    Route::post('/checkin/verify', [CheckinController::class, 'verify']);
});
