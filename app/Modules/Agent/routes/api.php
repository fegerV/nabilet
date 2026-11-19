<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Agent\Http\Controllers\Admin\AgentAdminController;

/*
 * Управление ИИ-агентом из админки.
 *
 * Раньше токен можно было выпустить только `php artisan` — на шаред-хостинге
 * терминала нет, и подключить агента было невозможно. Эти пути работают под
 * обычной админской сессией (auth:api + admin); организация берётся из
 * членства пользователя, а plaintext токена отдаётся один раз в create/rotate.
 *
 * `/mcp` (MCP-трафик самого агента) живёт в routes/ai.php под auth:sanctum —
 * эти два канала намеренно разные: человек управляет доступом, агент им пользуется.
 */
Route::prefix('admin/agent')->middleware(['auth:api', 'admin'])->group(function (): void {
    Route::get('tokens', [AgentAdminController::class, 'tokens']);
    Route::post('tokens', [AgentAdminController::class, 'issueToken']);
    Route::delete('tokens/{token}', [AgentAdminController::class, 'revokeToken'])->whereNumber('token');
    Route::post('tokens/{token}/rotate', [AgentAdminController::class, 'rotateToken'])->whereNumber('token');

    Route::get('actions', [AgentAdminController::class, 'pendingActions']);
    Route::post('actions/{action}/approve', [AgentAdminController::class, 'approve'])->whereNumber('action');
    Route::post('actions/{action}/reject', [AgentAdminController::class, 'reject'])->whereNumber('action');
});
