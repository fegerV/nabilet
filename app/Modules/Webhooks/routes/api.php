<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Modules\Webhooks\Http\Controllers\WebhookController;

/*
 * Webhooks Module API Routes
 * These routes are public - signature verification happens in controller
 */

// Payment provider webhooks
Route::post('/webhooks/payment/{provider}', [WebhookController::class, 'payment'])->name('webhooks.payment');

// Generic webhook endpoint with signature verification
Route::post('/webhooks/{type}', [WebhookController::class, 'handle'])->name('webhooks.handle');
