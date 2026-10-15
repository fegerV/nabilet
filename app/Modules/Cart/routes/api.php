<?php

declare(strict_types=1);

use Nabilet\Modules\Cart\Http\Controllers\CartController;
use Illuminate\Support\Facades\Route;

// Mounted at /api/v1 by bootstrap/app.php — do not repeat the version segment.
//
// СПЕКА-ПОВЕРХНОСТЬ (`nabilet_core_spec/openapi.yaml`, тег `Cart`).
// Корзина адресуется из пути по `public_id` (`CartIdPath`, `{cart}`), а не парой
// (`session_id` + `X-Cart-Token`) из тела. Право владения — тот же механизм:
// гостевой токен из `X-Cart-Token` совпадает с `carts.cart_token`, либо
// Bearer-пользователь владеет корзиной (`carts.user_id`). Ровно то, что спека
// описывает как `bearerAuth` ИЛИ `checkoutAuth`.
//
// `POST /cart/extend` оставлен ниже без изменений: спека описывает именно его
// (`extendGuestCartHold`, параметр `X-Cart-Token`), и расхождения там никогда
// не было.
Route::prefix('carts')->group(function () {
    // `createCart`: тело `{session_id}` → 201 + `CartResponse`.
    Route::post('/', [CartController::class, 'store']);

    // `getCart`: `{cart}` = `carts.public_id` — тот же идентификатор, который
    // отдаёт `CartResource` в поле `id`.
    Route::get('/{cart}', [CartController::class, 'showByPublicId']);

    // `addCartItem`: холд и позиция. `session_id` берётся из корзины, а не из тела.
    Route::post('/{cart}/items', [CartController::class, 'addItemTo']);

    // `deleteCartItem`: снять холд и вернуть место в продажу.
    Route::delete('/{cart}/items/{itemId}', [CartController::class, 'removeItemFrom'])->whereNumber('itemId');
});

//
// ЛЕГАСИ-ПОВЕРХНОСТЬ витрины. Пути ниже спеки НЕ описывает: магазин обращается
// к ним, потому что корзина ключуется парой (`session_id` + `X-Cart-Token`), а не
// идентификатором из пути. Это задолженность витрины, а не контракт: сгенерированный
// по спеке клиент эти пути не вызовет, и все четыре операции доступны ему выше —
// включая создание заказа из корзины (`POST /orders` с `cart_id`, `createOrder`).
//
// Удаляются вместе с миграцией витрины на спек-пути; пока живы — ради того, чтобы
// покупка не сломалась. Каждый метод вызывает тот же сервис, что и спека-путь,
// поэтому поведение одно, а не «почти одно».
Route::prefix('cart')->group(function () {
    Route::get('/', [CartController::class, 'show']);
    Route::post('/items', [CartController::class, 'addItem']);
    Route::delete('/items/{itemId}', [CartController::class, 'removeItem']);
    Route::post('/checkout', [CartController::class, 'checkout']);
    // B5: продление серверного холда. Гостевой токен (X-Cart-Token) обязателен —
    // без него сервер не найдёт корзину покупателя и вернёт CART_EXPIRED.
    Route::post('/extend', [CartController::class, 'extend']);
});
