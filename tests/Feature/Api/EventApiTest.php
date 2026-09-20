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
        $response = $this->getJson('/api/ping');

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
