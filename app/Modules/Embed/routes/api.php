<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Embed\Http\Controllers\EmbedController;

/*
| Embed — встраивание каталога, схемы зала, корзины и оплаты в чужие сайты
| (ТЗ §18, §48–51). Контракт: /api/v1/embed/* (docs/openapi.yaml).
|
| НИ ОДИН из этих маршрутов не закрыт `auth:api`, и это не упущение.
| Виджет работает на чужом сайте, в браузере без аккаунта и без cookie NABILET.
| Вызывающего идентифицирует пара capability: параметр `embed_token` (строка
| `api_keys` со скоупом `embed`) и заголовок `Origin`. Обе проверки, плюс
| владение ресурсом, доступ к событию и право на оплату — в
| `EmbedAccessService::authorize()`, который контроллер вызывает первым делом.
|
| `whereNumber` на событиях и сеансах — не косметика. Неявная привязка приводит
| путь к int, поэтому `/embed/events/1abc` открывал событие 1 (MySQL сравнивает
| BIGINT со строкой, приводя её; warning 1292 — не ошибка). С ограничением такой
| путь даёт 404. Тот же минимум выбран в `Events/routes/api.php` и
| `Sessions/routes/api.php`; перевод публичных ресурсов на `public_id` —
| отдельное решение с правкой витрины.
|
| Корзина и заказ, наоборот, адресуются `public_id` (ULID), потому что именно его
| отдаёт `POST /embed/carts` и `OrderResource.id`. `whereNumber` там стоять не
| должен: он отверг бы единственный идентификатор, который клиент получил.
*/
Route::prefix('embed')->group(function (): void {
    // Витрина мероприятия: страница события, расписание, схема зала.
    Route::get('events/{event}', [EmbedController::class, 'showEvent'])->whereNumber('event');
    Route::get('events/{event}/sessions', [EmbedController::class, 'listEventSessions'])->whereNumber('event');
    Route::get('sessions/{session}/seatmap', [EmbedController::class, 'seatmap'])->whereNumber('session');

    // Гостевая покупка: корзина → позиции → заказ → платёж.
    Route::post('carts', [EmbedController::class, 'createCart']);
    Route::post('carts/{cart}/items', [EmbedController::class, 'addCartItem']);
    Route::post('orders', [EmbedController::class, 'createOrder']);
    Route::post('orders/{order}/payment', [EmbedController::class, 'createPayment']);
});
