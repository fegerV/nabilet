<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Loyalty\Http\Controllers\AdminRaffleController;
use Nabilet\Modules\Loyalty\Http\Controllers\BonusController;
use Nabilet\Modules\Loyalty\Http\Controllers\FavoriteController;
use Nabilet\Modules\Loyalty\Http\Controllers\FollowController;
use Nabilet\Modules\Loyalty\Http\Controllers\LoyaltyLevelController;
use Nabilet\Modules\Loyalty\Http\Controllers\LoyaltySettingsController;
use Nabilet\Modules\Loyalty\Http\Controllers\RaffleController;
use Nabilet\Modules\Loyalty\Http\Controllers\ReferralController;

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

    // Уровень «Клуба зрителей» (фаза 3). Производное от числа оплаченных заказов;
    // пересчитывается обсервером при смене статуса заказа. Гость получает 401,
    // авторизованный без заказов — Старт с нулём оплаченных, а не 404.
    Route::get('/loyalty-level', [LoyaltyLevelController::class, 'show']);

    // «Пригласите друга» (фаза 4): код, ссылка `/r/{code}` и статистика.
    // Код выдаётся лениво — первое обращение заводит строку в
    // `referral_codes`, поэтому ответ всегда содержит ссылку, а не 404.
    Route::get('/referral', [ReferralController::class, 'show']);

    // «Счастливый билет» (фаза 5): в каких розыгрышах есть мои билеты и
    // выигрывал ли я. Участие — строка `raffle_entries`, и без аккаунта её
    // не найти: путь целиком под `auth:api`, гость получает 401.
    Route::get('/raffles', [RaffleController::class, 'index']);
});

//
// РОЗЫГРЫШИ «СЧАСТЛИВЫЙ БИЛЕТ» (фаза 5). Три зоны доступа:
//
//   /me/raffles            — кабинет покупателя (выше, под `auth:api`);
//   /raffles/{public_id}   — витрина: страница результатов БЕЗ авторизации.
//     Публичный путь намеренно: победителя нужно показать всем — «смотрите,
//     кто выиграл» — иначе страница результатов бесполезна. Наружу уходит
//     только маскированное имя победителя (`RaffleResultsResource`);
//   /admin/raffles/*       — управление организатором (`admin` поверх
//     `auth:api`): тираж двигает призы, поэтому он админский.
//
// Розыгрыш идентифицируется `public_id` (26 символов), не `id`: соглашение
// проекта — внутренний BIGINT не покидает API (`DATABASE.md` §9).
Route::get('/raffles/{public_id}', [RaffleController::class, 'results']);

Route::prefix('admin/raffles')->middleware(['auth:api', 'admin'])->group(function (): void {
    Route::get('/', [AdminRaffleController::class, 'index']);
    Route::post('/', [AdminRaffleController::class, 'store']);
    Route::get('/{raffle}', [AdminRaffleController::class, 'show']);
    Route::put('/{raffle}', [AdminRaffleController::class, 'update']);
    // Действия, а не правка полей: у каждой свои побочные эффекты.
    Route::post('/{raffle}/activate', [AdminRaffleController::class, 'activate']);
    Route::post('/{raffle}/cancel', [AdminRaffleController::class, 'cancel']);
    Route::post('/{raffle}/draw', [AdminRaffleController::class, 'draw']);
    Route::post('/{raffle}/notify', [AdminRaffleController::class, 'notify']);
});

//
// РАННИЙ ДОСТУП КЛУБА (фаза 3b). Настройка — админская: она открывает продажу
// раньше общего старта, поэтому `admin` поверх `auth:api`. Правило читает и
// магазин (через `LoyaltySettingsService`), но ПИШЕТ только админка.
//
// Один экран настроек по образцу `admin/mail`: GET отдаёт значения и список
// уровней для выпадающего списка порога, PUT сохраняет.
Route::prefix('admin/loyalty')->middleware(['auth:api', 'admin'])->group(function (): void {
    Route::get('/settings', [LoyaltySettingsController::class, 'show']);
    Route::put('/settings', [LoyaltySettingsController::class, 'update']);
});
