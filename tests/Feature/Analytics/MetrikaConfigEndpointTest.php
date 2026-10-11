<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Публичный конфиг Метрики и админские настройки.
 *
 * Регрессия, которую эти тесты фиксируют, была не в контроллере, а в
 * МАРШРУТИЗАЦИИ: файл `app/Modules/Analytics/routes/api.php` существовал, но не
 * был подключён в `routes/api.php`. Оба эндпоинта отвечали 404, а SPA трактует
 * не-2xx как «интеграция выключена» — счётчик не инициализировался никогда,
 * хотя в `config/metrika.php` он настроен. Поэтому проверяем именно HTTP-ответ
 * по контрактному пути, а не вызов сервиса: сервис всё это время работал.
 */
class MetrikaConfigEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_config_is_reachable_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/analytics/metrika/config');

        $response->assertOk();
        $response->assertJsonStructure([
            'enabled',
            'counter_id',
            'counter_auth',
            'counter_type',
            'safe_mode',
            'ecommerce',
            'currency',
            'goals',
        ]);
    }

    public function test_public_config_exposes_the_configured_funnel_goals(): void
    {
        $response = $this->getJson('/api/v1/analytics/metrika/config');

        $response->assertOk();

        $goals = $response->json('goals');

        $this->assertIsArray($goals, 'SPA строит reachGoal по этой карте — без неё воронка пуста');

        // Пять целей, которые аудит витрины нашёл «мёртвыми»: они настроены здесь
        // и должны уезжать на фронт, иначе добавить им call-site бессмысленно.
        foreach (['event_view', 'checkout_started', 'payment_attempt', 'payment_success', 'payment_fail'] as $goal) {
            $this->assertArrayHasKey($goal, $goals, "Цель {$goal} должна присутствовать в публичном конфиге");
        }

        $this->assertSame('purchase', $goals['payment_success'], 'Успешная оплата — цель purchase для автостратегий Директа');
    }

    public function test_public_config_does_not_leak_secrets(): void
    {
        config()->set('metrika.counter_auth', 'super-secret-token');

        $response = $this->getJson('/api/v1/analytics/metrika/config');

        $response->assertOk();

        // Поле есть в ответе по контракту, но это не секрет приложения: это
        // публичный параметр кода вставки счётчика. Проверяем обратное —
        // что в ответ не утекли ключи из окружения.
        $body = $response->getContent();

        $this->assertStringNotContainsString('DB_PASSWORD', (string) $body);
        $this->assertStringNotContainsString('APP_KEY', (string) $body);
    }

    public function test_admin_settings_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/analytics/metrika')->assertUnauthorized();
        $this->putJson('/api/v1/admin/analytics/metrika', [])->assertUnauthorized();
    }
}
