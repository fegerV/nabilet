<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Nabilet\Core\Errors\AppError;
use Nabilet\Core\Errors\TenantContextMissingError;

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
    ->withProviders([])
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ── Global stack, in order ───────────────────────────────────────────
        $middleware->append(\Nabilet\Core\Http\Middleware\AssignRequestId::class);
        $middleware->append(\Nabilet\Core\Http\Middleware\ResolveOrganizationContext::class);
        $middleware->append(\Nabilet\Core\Http\Middleware\ApplySecurityHeaders::class);

        $middleware->api(prepend: [
            \Nabilet\Core\Http\Middleware\ForceJsonResponse::class,
        ]);

        $middleware->web(append: [
            \Nabilet\Core\Http\Middleware\SetLocale::class,
        ]);

        // ── Aliases ──────────────────────────────────────────────────────────
        // Only kernel middleware are aliased here. Modules register their own
        // aliases from their service providers, so that removing a module cannot
        // leave a dangling alias pointing at a missing class.
        $middleware->alias([
            'organization' => \Nabilet\Core\Http\Middleware\ResolveOrganizationContext::class,
            'permission' => \Nabilet\Core\Http\Middleware\RequirePermission::class,
            'idempotent' => \Nabilet\Core\Http\Middleware\EnsureIdempotency::class,
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
        $exceptions->render(function (Throwable $e, Request $request) {
            $requestId = (string) ($request->attributes->get('request_id') ?? '');

            if ($e instanceof AppError && $e->operational) {
                return response()->json($e->toResponse($requestId), $e->status, [
                    'Content-Type' => 'application/json',
                ]);
            }

            // Tenant-context bugs are programming errors. Report them loudly but
            // never expose the reason — an attacker probing for a scoping bypass
            // must not learn that a bypass exists. The body still uses the §66
            // envelope, with a generic code and no internal detail.
            if ($e instanceof TenantContextMissingError) {
                report($e);

                $payload = ['error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'Something went wrong. Please try again.',
                ]];

                if ($requestId !== '') {
                    $payload['error']['request_id'] = $requestId;
                }

                return response()->json($payload, 500, ['Content-Type' => 'application/json']);
            }

            return null; // fall through to Laravel's default handling
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })
    ->create();
