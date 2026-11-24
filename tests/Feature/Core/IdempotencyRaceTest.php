<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nabilet\Core\Idempotency\IdempotencyHeader;
use Nabilet\Tests\Support\StaffAuth;
use Tests\TestCase;

/**
 * Гонка middleware идемпотентности на уровне HTTP + БД.
 *
 *Middleware не висит на продакшн-маршрутах (там защита доменная), поэтому здесь
 * тестовый роут с алиасом `idempotent`. Второй запрос внедряется через `DB::listen`
 * в точных точках между find() и lock() и сразу после lock() — без потоков и sleep.
 * Главное свойство: хендлер вызывается ровно один раз, сколько бы запросов ни
 * оказалось в гонке.
 */
final class IdempotencyRaceTest extends TestCase
{
    use RefreshDatabase;
    use StaffAuth;

    private const URL = '/api/v1/__idempotency-race';
    private const KEY = 'race-key-1';

    private int $effects = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Idempotency race',
            'slug' => 'idempotency-race-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAsStaff($organizationId);

        Route::post(self::URL, function () {
            $this->effects += 1;

            return response()->json(['ok' => true, 'effects' => $this->effects], 201);
        })->middleware('idempotent:race.create');
    }

    public function test_a_request_losing_the_race_between_find_and_lock_does_not_execute_twice(): void
    {
        $injected = false;
        $loserStatus = null;

        DB::listen(function ($query) use (&$injected, &$loserStatus): void {
            if ($injected || $query->connectionName !== 'mysql') {
                return;
            }
            $sql = strtolower($query->sql);
            // Первый SELECT строки идемпотентности: лок ещё не взят.
            if (! str_contains($sql, 'select') || ! str_contains($sql, '`idempotency_keys`')) {
                return;
            }
            $injected = true;
            // Победитель успевает полностью выполниться до нашего lock().
            $loserStatus = $this->postJson(self::URL, ['v' => 1], $this->headers())->status();
        });

        $response = $this->postJson(self::URL, ['v' => 1], $this->headers());

        self::assertTrue($injected, 'гонка должна была внедриться между find() и lock()');
        self::assertSame(201, $loserStatus);
        $response->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        self::assertSame(1, $this->effects, 'конкурирующие запросы выполнили хендлер дважды');
        self::assertSame(1, (int) DB::table('idempotency_keys')->count());
    }

    public function test_a_request_arriving_after_the_lock_gets_in_flight_conflict(): void
    {
        $injected = false;
        $loser = null;

        DB::listen(function ($query) use (&$injected, &$loser): void {
            if ($injected || $query->connectionName !== 'mysql') {
                return;
            }
            $sql = strtolower($query->sql);
            // Вставка лока уже выполнена: строка видна, ответ ещё не записан.
            if (! str_contains($sql, 'insert') || ! str_contains($sql, '`idempotency_keys`')) {
                return;
            }
            $injected = true;
            $loser = $this->postJson(self::URL, ['v' => 1], $this->headers());
        });

        $response = $this->postJson(self::URL, ['v' => 1], $this->headers());

        self::assertTrue($injected, 'гонка должна была внедриться сразу после lock()');
        $loser->assertStatus(409)->assertJsonPath('error.code', 'REQUEST_ALREADY_PROCESSED');
        $response->assertCreated();
        self::assertSame(1, $this->effects);
    }

    public function test_a_settled_request_is_replayed_without_executing_the_handler(): void
    {
        $first = $this->postJson(self::URL, ['v' => 1], $this->headers());
        $first->assertCreated()->assertJsonPath('effects', 1);

        $again = $this->postJson(self::URL, ['v' => 1], $this->headers());
        $again->assertStatus(201)
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('effects', 1);

        self::assertSame(1, $this->effects);
    }

    public function test_the_same_key_with_a_different_payload_is_a_conflict(): void
    {
        $this->postJson(self::URL, ['v' => 1], $this->headers())->assertCreated();

        $this->postJson(self::URL, ['v' => 2], $this->headers())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        self::assertSame(1, $this->effects);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [IdempotencyHeader::NAME => self::KEY];
    }
}
