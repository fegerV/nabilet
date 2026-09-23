<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_endpoint(): void
    {
        // API смонтирован с префиксом версии (`apiPrefix: 'api/v1'` в bootstrap/app.php),
        // поэтому /api/ping — это 404. Версия в пути обязательна.
        $response = $this->getJson('/api/v1/ping');

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'timestamp']);
    }

    public function test_events_endpoint_exists(): void
    {
        $response = $this->getJson('/api/v1/events');

        // Should return 200 or 401/403 if auth required
        $this->assertTrue(in_array($response->status(), [200, 401, 403]));
    }
}
