<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `GET /api/v1/health` при НЕДОСТУПНОЙ базе — тот самый случай, ради которого
 * эндпоинт существует.
 *
 * Класс намеренно НЕ использует `RefreshDatabase`. Тому две причины:
 *   1. Тест не нуждается в схеме — ему нужно, чтобы соединения не было.
 *   2. `RefreshDatabase` оборачивает тест в транзакцию на соединении, которое
 *      здесь принудительно разрывается. Откат такой транзакции на teardown
 *      сам стал бы источником ошибки, не связанной с проверяемым поведением.
 *
 * Проверяется именно код ответа, а не только поле `status`: монитор и
 * балансировщик смотрят на HTTP-статус. Ровно это и было дефектом до правок —
 * `/ping` и `/up` отвечали 200 при мёртвой базе, то есть давали ложную
 * зелёную лампочку.
 */
final class HealthEndpointUnreachableDatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Порт 1 на localhost закрыт всегда, и отказ приходит мгновенно
        // (ECONNREFUSED), а не по таймауту.
        config([
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => 1,
        ]);

        DB::purge('mysql');
    }

    protected function tearDown(): void
    {
        DB::purge('mysql');

        parent::tearDown();
    }

    public function test_health_returns_503_when_the_database_is_unreachable(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('database', 'error');
    }

    public function test_health_response_still_matches_the_spec_shape_when_down(): void
    {
        // Спека объявляет для 503 ту же схему `HealthResponse`, что и для 200.
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503)
            ->assertJsonStructure(['status', 'database', 'redis', 'queue', 'version', 'timestamp']);
    }

    public function test_health_does_not_leak_the_driver_error(): void
    {
        // Внутренний текст исключения (хост, порт, драйвер) не должен попадать
        // в публичный ответ — это разведка перед атакой.
        $payload = $this->getJson('/api/v1/health')->getContent();

        $this->assertFalse(str_contains($payload, 'SQLSTATE'));
        $this->assertFalse(str_contains($payload, 'PDOException'));
        $this->assertFalse(str_contains($payload, 'Connection refused'));
    }
}
