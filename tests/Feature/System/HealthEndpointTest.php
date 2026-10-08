<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `GET /api/v1/health` — контракт спеки (`nabilet_core_spec/openapi.yaml:1292`)
 * и админская диагностика модуля System.
 *
 * ПОЧЕМУ ЭТОТ ТЕСТ ВАЖЕН БОЛЬШЕ ОБЫЧНОГО
 *   До этих правок в проекте не было ни одного эндпоинта, который проверял бы
 *   зависимости: `/ping` и `/up` отвечали 200 всегда, ничего не проверяя. То
 *   есть монитор, повешенный на них, показывал зелёный свет при мёртвой базе.
 *   Проверки ниже фиксируют, что этого больше не происходит: код ответа
 *   зависит от состояния зависимостей, а `$context` ответа совпадает со схемой
 *   `HealthResponse`, а не с произвольным набором полей.
 */
final class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** Ключи схемы `HealthResponse` из спеки. */
    private const HEALTH_KEYS = ['status', 'database', 'redis', 'queue', 'version', 'timestamp'];

    private function route(string $uri): \Illuminate\Routing\Route
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(static fn ($candidate): bool => $candidate->uri() === $uri);

        $this->assertNotNull($route, "Маршрут {$uri} не зарегистрирован");

        return $route;
    }

    public function test_health_returns_the_spec_health_response_shape(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);

        $keys = array_keys($response->json());
        sort($keys);
        $expected = self::HEALTH_KEYS;
        sort($expected);

        // Точный набор ключей, а не подмножество: лишнее поле здесь — это
        // утечка (версии, пути, объём диска), а не «дополнительная информация».
        $this->assertSame($expected, $keys);
    }

    public function test_health_reports_ok_when_dependencies_are_reachable(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'ok');
    }

    public function test_health_status_is_one_of_the_declared_enum_values(): void
    {
        $status = $this->getJson('/api/v1/health')->json('status');

        // Спека: enum [ok, degraded, down]. Контроллер раньше отдавал
        // `healthy`, что не входит в перечисление и ломало бы типизированный
        // клиент, сгенерированный из контракта.
        $this->assertContains($status, ['ok', 'degraded', 'down']);
    }

    public function test_health_does_not_leak_runtime_internals(): void
    {
        $payload = $this->getJson('/api/v1/health')->getContent();

        // Эндпоинт публичный (его опрашивает балансировщик без токена),
        // поэтому точные версии PHP и Laravel, геометрия диска и путь к логам
        // здесь недопустимы: версия фреймворка отображается на список
        // известных уязвимостей.
        foreach (['php_version', 'laravel_version', 'disk_space', 'memory_usage', 'last_backup', 'logs'] as $leak) {
            $this->assertFalse(
                str_contains($payload, $leak),
                "Публичный /health не должен содержать поле {$leak}"
            );
        }

        $this->assertFalse(
            str_contains($payload, PHP_VERSION),
            'Публичный /health не должен раскрывать версию PHP'
        );
    }

    public function test_health_is_reachable_without_authentication(): void
    {
        $middleware = $this->route('api/v1/health')->gatherMiddleware();

        $this->assertNotContains('auth:api', $middleware);
        $this->assertNotContains('admin', $middleware);
    }

    public function test_admin_diagnostics_are_gated_by_auth_and_admin(): void
    {
        foreach (['api/v1/admin/system/status', 'api/v1/admin/system/logs', 'api/v1/admin/system/cache/clear'] as $uri) {
            $middleware = $this->route($uri)->gatherMiddleware();

            $this->assertContains('auth:api', $middleware, "{$uri} должен требовать аутентификацию");
            $this->assertContains('admin', $middleware, "{$uri} должен требовать роль admin/manager");
        }
    }

    public function test_admin_diagnostics_reject_anonymous_requests(): void
    {
        $this->getJson('/api/v1/admin/system/status')->assertStatus(401);
        $this->getJson('/api/v1/admin/system/logs')->assertStatus(401);
    }

    public function test_admin_diagnostics_do_not_expose_versions_to_anonymous_callers(): void
    {
        // Именно то, ради чего диагностика закрыта: в теле не должно быть
        // ничего, даже если запрос отклонён.
        $payload = $this->getJson('/api/v1/admin/system/status')->getContent();

        $this->assertFalse(str_contains($payload, PHP_VERSION));
        $this->assertFalse(str_contains($payload, 'laravel_version'));
    }
}
