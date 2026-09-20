<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_endpoint_exists(): void
    {
        $response = $this->getJson('/api/v1/orders');

        $this->assertTrue(in_array($response->status(), [200, 401, 403]));
    }
}
