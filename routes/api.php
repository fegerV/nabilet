<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

/*
 * Liveness-проба: отвечает «процесс жив и обслуживает запросы».
 *
 * ВНИМАНИЕ: этот маршрут НИЧЕГО не проверяет и отвечает 200 всегда — в том
 * числе при недоступной базе. Как проверка работоспособности он непригоден, и
 * раньше именно в этой роли он и использовался (`docs/SERVER-HEALTH.md`,
 * отчёты по живой среде): монитор показывал зелёный свет на мёртвой БД.
 *
 * Состояние зависимостей проверяет `GET /api/v1/health`
 * (`app/Modules/System/routes/api.php`): он отвечает 503 при отказе критичной
 * зависимости. Оставляем `/ping` как дешёвую liveness-пробу для
 * существующего smoke-теста и не меняем его семантику, но не путать одно с
 * другим: монитор должен смотреть на `/health`.
 */
Route::get('/ping', fn () => response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]));

/*
|--------------------------------------------------------------------------
| Module Routes — Safe Route Loader
|--------------------------------------------------------------------------
|
| All modules are now enabled. Missing classes have been created as
| aliases/extensions to resolve the DI chain.
*/

if (file_exists(__DIR__ . '/../app/Modules/Auth/routes/api.php')) {
    require __DIR__ . '/../app/Modules/Auth/routes/api.php';
}

$moduleRoutes = [
    __DIR__ . '/../app/Modules/Events/routes/api.php',
    __DIR__ . '/../app/Modules/Sessions/routes/api.php',
    __DIR__ . '/../app/Modules/Venues/routes/api.php',
    __DIR__ . '/../app/Modules/Inventory/routes/api.php',
    __DIR__ . '/../app/Modules/Cart/routes/api.php',
    __DIR__ . '/../app/Modules/Orders/routes/api.php',
    __DIR__ . '/../app/Modules/Payments/routes/api.php',
    __DIR__ . '/../app/Modules/Tickets/routes/api.php',
    __DIR__ . '/../app/Modules/Webhooks/routes/api.php',
    // Редактирование шаблонов транзакционных писем (админка).
    __DIR__ . '/../app/Modules/Notifications/routes/api.php',
    // Медиа: список, загрузка, метаданные, удаление (контракт: /api/v1/media).
    // Файл маршрутов существовал не всегда: провайдер модуля пытался загрузить
    // его из `boot()`, но каталога `routes/` не было, а сам провайдер не был
    // зарегистрирован — поэтому вызов не выполнялся и ошибка была не видна.
    __DIR__ . '/../app/Modules/Media/routes/api.php',
    __DIR__ . '/../app/Modules/Core/Users/routes/api.php',
    __DIR__ . '/../app/Modules/Core/Organizations/routes/api.php',
    __DIR__ . '/../app/Modules/Venues/Halls/routes/api.php',
    // Модуль Seo больше не имеет api-маршрутов: карты сайта перенесены на
    // корневые адреса `/sitemap.xml` и зарегистрированы в
    // `app/Modules/Seo/routes/web.php` (аудит P-10a). Карта — не часть API:
    // она не версионируется и адресуется роботом, а префикс `/api/v1`
    // заставлял `robots.txt` одновременно запрещать адрес карты и объявлять
    // его в директиве `Sitemap`. Старые пути `/api/v1/sitemap*.xml` отвечают
    // 301 через nginx, а не отсюда: на api-группу навешан `ForceJsonResponse`,
    // поэтому маршрут внутри `/api/v1` отдал бы JSON вместо редиректа.
    // Конструктор витрины: публичный конфиг + админские настройки.
    __DIR__ . '/../app/Modules/Storefront/routes/api.php',
    // Аналитика: публичный конфиг счётчика + админские настройки Метрики.
    // Файл существовал, но не был подключён — раздел «Интеграции» в админке
    // упирался в 404 для обоих эндпоинтов.
    __DIR__ . '/../app/Modules/Analytics/routes/api.php',
    // Состояние системы: публичный GET /health (контракт openapi.yaml:1292)
    // и админская диагностика. Публичный эндпоинт обязан быть подключён:
    // без него монитор судит о живости по /ping, который всегда отвечает 200.
    __DIR__ . '/../app/Modules/System/routes/api.php',
    // Embed: встраивание мероприятия, схемы зала, корзины и оплаты в чужие
    // сайты (контракт: /api/v1/embed/*, ТЗ §18). Авторизация — не `auth:api`,
    // а пара embed-токен + Origin; см. докблок файла маршрутов и
    // `EmbedAccessService`. Файл существовал не всегда: модуль был пустым
    // провайдером, поэтому все восемь путей контракта отвечали 404.
    __DIR__ . '/../app/Modules/Embed/routes/api.php',
    // Pricing: промокоды (контракт: /api/v1/promo-codes — list/create/get/
    // patch/delete/validate). Файл существовал не всегда: модуль содержал
    // только домен (`PromoEvaluator`) и провайдер-заглушку, поэтому все шесть
    // путей контракта отвечали 404, а checkout отказывал любому коду со
    // стабильным PROMO_CODE_NOT_SUPPORTED.
    __DIR__ . '/../app/Modules/Pricing/routes/api.php',
    // Check-in: погашение билетов с устройства (контракт: /api/v1/checkin/
    // validate, /use, /sync под `checkerAuth` — bearer-токен устройства из
    // `checkin_devices.device_token_hash`) и админская часть: выдача и отзыв
    // устройств, журнал входов. У модуля не было HTTP-слоя ВООБЩЕ — три пути
    // контракта отвечали 404, а колонка под токен устройства не имела ни
    // писателя, ни читателя. Подробности — в шапке файла маршрутов модуля.
    __DIR__ . '/../app/Modules/Checkin/routes/api.php',
    // Клуб зрителей, фаза 1 (`docs/LOYALTY-PLAN.md`): избранное и подписки
    // покупателя — `/me/favorites`, `/me/events/{event}/favorite`,
    // `/me/follows`, `/me/events/{event}/follow`. Весь префикс под `auth:api`:
    // без аккаунта избранное негде хранить. Файл обязан быть подключён здесь —
    // `loadRoutesFrom()` из провайдера зарегистрировал бы те же пути в корне,
    // вне /api/v1 и его middleware.
    __DIR__ . '/../app/Modules/Loyalty/routes/api.php',
    // Matrix (Element X): административные уведомления в комнату через удалённый
    // Synapse — `/admin/matrix/settings` (GET/PUT) и `/admin/matrix/test`. Весь
    // раздел под `auth:api` + `admin`: здесь лежит токен доступа к Synapse.
    // Файл обязан быть подключён здесь — `loadRoutesFrom()` из провайдера
    // зарегистрировал бы те же пути в корне, вне /api/v1 и его middleware.
    __DIR__ . '/../app/Modules/Matrix/routes/api.php',
    // Бэкапы: ручное скачивание локальных копий (админка) — `/admin/backups`.
    // Локальные копии появляются при запуске `backup:run --keep-local`.
    __DIR__ . '/../app/Modules/Backups/routes/api.php',
];

foreach ($moduleRoutes as $routeFile) {
    if (file_exists($routeFile)) {
        require $routeFile;
    }
}