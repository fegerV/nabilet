<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Loyalty\Http\Controllers\BonusController;
use Nabilet\Modules\Loyalty\Http\Controllers\FavoriteController;
use Nabilet\Modules\Loyalty\Http\Controllers\FollowController;

// Личный кабинет покупателя: «❤️ Избранное», «🔔 Подписки» и бонусный счёт.
//
// Модуль Loyalty, фазы 1 и 2a `docs/LOYALTY-PLAN.md`. Весь префикс `me` закрыт
// `auth:api`: без аккаунта негде хранить избранное, и гость обязан получить
// 401, а не тихо потерять отметку. Ни один метод не принимает чужой
// идентификатор пользователя — владелец всегда из Bearer-токена.
//
// Мероприятие в пути идентифицируется `public_id` (26 символов), не
// `id`: соглашение проекта — внутренний BIGINT не покидает API
// (`DATABASE.md` §9). Неявная привязка модели привела бы строку к int и
// открыла бы `/me/events/1/favorite`, поэтому метод принимает `string` и
// разрешает сам (`LoyaltyController::resolveEvent`).
//
// Маршруты НЕ грузятся из провайдера модуля: единственный владелец
// регистрации — `routes/api.php`, из-под которого файл получает префикс
// `/api/v1` (`tools/verify-route-ownership.php`).
Route::prefix('me')->middleware('auth:api')->group(function (): void {
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/events/{event}/favorite', [FavoriteController::class, 'store']);
    Route::delete('/events/{event}/favorite', [FavoriteController::class, 'destroy']);

    Route::get('/follows', [FollowController::class, 'index']);
    Route::post('/events/{event}/follow', [FollowController::class, 'store']);
    Route::delete('/events/{event}/follow', [FollowController::class, 'destroy']);

    // Бонусный счёт, фаза 2a. Баланс отдаётся и при отсутствии счёта — нулём,
    // а не 404: счёт заводится лениво, и «бонусов ещё нет» — это ноль бонусов.
    Route::get('/bonus', [BonusController::class, 'balance']);
    Route::get('/bonus/transactions', [BonusController::class, 'transactions']);
});
