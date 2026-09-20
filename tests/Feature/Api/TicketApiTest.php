<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tickets_endpoint_exists(): void
    {
        $response = $this->getJson('/api/v1/tickets');

        $this->assertTrue(in_array($response->status(), [200, 401, 403]));
    }

    public function test_checkin_scan_endpoint_exists(): void
    {
        $response = $this->postJson('/api/v1/tickets/checkin/scan', []);

        // Should return validation error or auth error
        $this->assertTrue(in_array($response->status(), [401, 403, 422]));
    }
}
