<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Embed\Http\Controllers\Admin\EmbedDomainController;
use Nabilet\Modules\Embed\Http\Controllers\Admin\EmbedKeyController;
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

/*
| Провиджининг встраивания: чем выпускают embed-токен и чем заполняют whitelist
| (контракт openapi: /api/v1/admin/embed/keys, /api/v1/admin/embed/domains).
|
| ДО ЭТОГО БЛОКА фича была недостижима: `EmbedAccessService` проверял ключ со
| скоупом `embed` и домен в `embed_domains`, но ни один код приложения не писал
| ни ту, ни другую таблицу. Весь `/api/v1/embed/*` отвечал 403 на любой запрос,
| и включить встраивание было нечем — ни настройкой, ни сидом.
|
| `auth:api` + `admin` — как у остального CRUD админки: раздача embed-токена даёт
| право продавать от имени организации на чужом сайте, то есть это действие
| администратора, а не сотрудника.
|
| `whereNumber` здесь НЕТ у ключей и ЕСТЬ у доменов — и это не непоследовательность.
| Ключ адресуется `public_id` (ULID) или числом, поэтому ограничение пути сломало бы
| половину контракта. У `embed_domains` колонки `public_id` НЕТ вовсе, поэтому
| единственный честный идентификатор — числовой id, и `whereNumber` не даёт пути
| `/1abc` дойти до `IdentifierLookup` (там нечисловое значение ушло бы в
| `where('public_id', …)` — в несуществующую колонку, то есть в 500).
*/
Route::prefix('admin/embed')->middleware(['auth:api', 'admin'])->group(function (): void {
    Route::get('keys', [EmbedKeyController::class, 'index']);
    Route::post('keys', [EmbedKeyController::class, 'store']);
    Route::patch('keys/{key}', [EmbedKeyController::class, 'update']);

    Route::get('domains', [EmbedDomainController::class, 'index']);
    Route::post('domains', [EmbedDomainController::class, 'store']);
    Route::patch('domains/{embedDomain}', [EmbedDomainController::class, 'update'])->whereNumber('embedDomain');
    Route::delete('domains/{embedDomain}', [EmbedDomainController::class, 'destroy'])->whereNumber('embedDomain');
});
