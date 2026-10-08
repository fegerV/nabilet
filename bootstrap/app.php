<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Nabilet\Core\Http\ApiExceptionRenderer;

/**
 * Application bootstrap (Laravel 13 style).
 *
 * Two things here carry architectural weight:
 *
 *  1. Middleware ORDER. It is fixed and explicit because several middlewares are
 *     load-bearing for security:
 *       request-id  -> so every log line and error response is correlatable
 *       tenancy     -> resolves organization_id BEFORE any policy or scope runs
 *       auth        -> after tenancy, because the user's organization comes from
 *                      the authenticated principal
 *       idempotency -> after auth, so a replay cannot be attributed to another user
 *     Getting this order wrong produces bugs that look random.
 *
 *  2. Error rendering. Every deliberate failure is an AppError carrying a stable
 *     `errorCode`. The wire format is the §66 envelope
 *     `{"error":{"code","message","details","request_id"}}` — never RFC 7807.
 *     Non-operational errors (bugs) are logged and replaced by a generic 500 —
 *     stack traces and internal messages must never reach a client.
 */
return Application::configure(basePath: dirname(__DIR__))
    // Providers here are kept explicit and minimal on purpose (see the note below).
    // The Installer provider is registered so its Blade view namespace (`installer::`)
    // is mounted — without it `GET /install` would 500 with "view not found". It no
    // longer loads routes (those are required from routes/web.php), so this is safe.
    ->withProviders([
        \Nabilet\Modules\Installer\Providers\InstallerServiceProvider::class,
        // Провайдер заказов бутится явно: он вешает OrderObserver, единственную
        // точку, откуда расходятся письма и исходящие вебхуки по статусу заказа.
        // Маршруты модуля он не грузит (они подключены централизованно в
        // routes/api.php), так что регистрация здесь безопасна — префикс /api/v1
        // никуда не теряется.
        \Nabilet\Modules\Orders\Providers\OrderServiceProvider::class,
        // Провайдер уведомлений применяет SMTP-настройки из админки к runtime-
        // конфигу mail. Это нужно на КАЖДОМ бутстрапе: письма уходят и из
        // веб-запроса, и из воркера очереди, и оба обязаны видеть одно и то же.
        // Маршруты модуля тоже подключены централизованно, так что префикс не
        // теряется.
        \Nabilet\Modules\Notifications\Providers\NotificationsServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    // Commands are registered explicitly because `withProviders([])` below means no
    // module service provider ever boots (see bootstrap/providers.php for why booting
    // the whole registry is not safe yet). `routes/console.php` schedules
    // `seats:clear-expired`, and an artisan schedule that references an unregistered
    // command fails as a whole — so this one is wired up here by hand.
    ->withCommands([
        \Nabilet\Modules\Inventory\Console\ClearExpiredHoldsCommand::class,
        // Поднимает повторы доставки вебхуков по next_retry_at. Зарегистрирован
        // здесь по той же причине, что и очистка холдов: провайдеры модулей не
        // бутятся, а планировщик ссылается на команду по имени.
        \Nabilet\Modules\Webhooks\Console\RetryPendingWebhookDeliveriesCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // ── Global stack, in order ───────────────────────────────────────────
        $middleware->append(\Nabilet\Core\Http\Middleware\AssignRequestId::class);
        $middleware->append(\Nabilet\Core\Http\Middleware\ResolveOrganizationContext::class);
        $middleware->append(\Nabilet\Core\Http\Middleware\ApplySecurityHeaders::class);
        // Запрет индексации служебных страниц (`X-Robots-Tag`). Стоит в global
        // stack, а не в группе `web`, потому что витрина отдаётся и из web-роутов
        // (`/`, `/checkout`), и из API (`/api/v1/my-tickets`) — а закрывать надо
        // оба входа. Подробности и список путей — в самом классе.
        $middleware->append(\Nabilet\Core\Http\Middleware\PreventIndexingOfServicePages::class);

        $middleware->api(prepend: [
            \Nabilet\Core\Http\Middleware\ForceJsonResponse::class,
        ]);

        $middleware->web(append: [
            \Nabilet\Core\Http\Middleware\SetLocale::class,
        ]);

        // Installer (WordPress-стиль мастер установки) — одноразовый pre-auth
        // эндпоинт, доступный только до создания storage/install.lock. Его POST
        // шлёт JSON без CSRF-токена (как и положено установщику), поэтому
        // PreventRequestForgery для /install отключаем. Повторный запуск всё равно
        // блокируется контроллером по install.lock.
        $middleware->validateCsrfTokens(except: ['install', 'install/*']);

        // ── Aliases ──────────────────────────────────────────────────────────
        // Only kernel middleware are aliased here. Modules register their own
        // aliases from their service providers, so that removing a module cannot
        // leave a dangling alias pointing at a missing class.
        $middleware->alias([
            'organization' => \Nabilet\Core\Http\Middleware\ResolveOrganizationContext::class,
            'permission' => \Nabilet\Core\Http\Middleware\RequirePermission::class,
            'idempotent' => \Nabilet\Core\Http\Middleware\EnsureIdempotency::class,
            'admin' => \Nabilet\Core\Http\Middleware\EnsureAdminRole::class,
        ]);

        // Trusted proxies so client IPs survive a load balancer / CDN. Without this
        // every IP-based rule (rate limits, IP filters, audit log) sees the proxy.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES') ? explode(',', (string) env('TRUSTED_PROXIES')) : null,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every JSON failure is rendered by `ApiExceptionRenderer`, which maps both
        // `AppError` and the framework's own exceptions (validation, 404, 401, 403,
        // 429) onto the §66 envelope. Read that class before changing this: Laravel's
        // defaults are not merely differently-shaped, they leak model namespaces and
        // — with APP_DEBUG on — stack traces and absolute paths.
        $exceptions->render(function (Throwable $e, Request $request) {
            // HTML surfaces (the admin SPA, the installer) keep Laravel's own
            // rendering; a JSON envelope in a browser would be a regression.
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiExceptionRenderer::render($e, $request);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })
    ->create();
