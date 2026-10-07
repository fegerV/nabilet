<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Auth\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| Auth Module API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);

    /*
     * `throttle:auth` = 5 per minute per IP (`Nabilet\Core\Http\Middleware\RateLimiter`).
     * Both password endpoints need it: login for brute force, forgot-password
     * because it makes us send mail to an address the caller chose — without a
     * limit it is an open relay for spam aimed at anyone, from our domain.
     */
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:api');

    /*
     * Paths match `docs/openapi.yaml` (`/auth/password/forgot`, `/auth/password/reset`,
     * `/auth/email/verify`). They previously read `/forgot-password` and
     * `/reset-password`, which is what `docs/SERVER-HEALTH.md` recorded as a
     * contract divergence — a generated client would have called paths this
     * application does not serve.
     */
    Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');
    Route::post('/email/verify', [AuthController::class, 'verifyEmail'])->middleware('auth:api');
});