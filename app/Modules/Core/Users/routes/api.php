<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Core\Users\Http\Controllers\UserController;

/*
 * Users Module API Routes
 *
 * ВСЕ маршруты — админские: `admin` (`EnsureAdminRole`) в дополнение к `auth:api`.
 *
 * Раньше здесь был только `auth:api`, и это делало модуль единственным
 * исключением среди соседей (Events, Orders, Venues, Tickets, Pricing, Webhooks,
 * System — везде `admin` на write-роуты). Последствие — не «чтение чужого
 * списка»: `POST /users/{publicId}/roles` позволял любому аутентифицированному
 * покупателю выдать себе роль администратора, то есть повысить привилегии.
 * Список пользователей (`GET /users`) тоже не публичный: это PII всех клиентов.
 */

// Mounted at /api/v1 by bootstrap/app.php — the prefix used to be repeated here,
// which produced /api/v1/api/v1/users and left these routes unreachable at the
// spec paths (/api/v1/users/{user}/roles).
Route::middleware(['auth:api', 'admin'])->group(function () {
    // ВАЖНО: `/users/search` объявлен ДО `/users/{publicId}`. Laravel сопоставляет
    // маршруты в порядке регистрации, поэтому при обратном порядке
    // `GET /users/search` попадал в `show('search')` и отвечал 404: `search` —
    // синтаксически допустимый `publicId` (ULID-строка), и никакой шаблон его
    // не отсекает.
    Route::get('/users/search', [UserController::class, 'search'])->name('users.search');

    // Справочник ролей — тоже ДО `/users/{publicId}`: иначе `roles` уедет в
    // `show('roles')` и ответит 404 (та же ловушка, что у `/users/search`).
    Route::get('/users/roles', [UserController::class, 'roles'])->name('users.roles.index');

    // User management
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/{publicId}', [UserController::class, 'show'])->name('users.show');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{publicId}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{publicId}', [UserController::class, 'destroy'])->name('users.destroy');

    // User roles
    Route::post('/users/{publicId}/roles', [UserController::class, 'assignRole'])->name('users.roles.assign');
    Route::delete('/users/{publicId}/roles/{roleId}', [UserController::class, 'removeRole'])->name('users.roles.remove');
});
