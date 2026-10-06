<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Venues\Halls\Services\HallService;
use Nabilet\Modules\Venues\Models\Hall;
use Tests\TestCase;

/**
 * Геометрия секторов схемы зала: координаты, перекрытие и конвертация в инвентарь.
 *
 * Повод — прогон конструктора залов на тестовой БД. Редактор
 * (`resources/js/pages/hall-editor/HallEditorPage.vue`) хранит координаты мест
 * ЛОКАЛЬНО внутри группы сектора (`Konva.Group({ x: sector.x, y: sector.y })`),
 * а смещение — в `sector.x` / `sector.y`. Сервер же сравнивал «сырые» координаты
 * как координаты холста, поэтому два сектора, разнесённых по полотну, считались
 * наложенными: зал из ≥2 секторов не сохранялся (422 «Seats must not overlap»).
 * Тот же пропуск смещения в `HallSchemaVersion::toInventoryFormat()` сводил все
 * сектора в одну точку нормированного пространства, и координатная карта витрины
 * (`CoordSeatMap`) рисовала их друг поверх друга.
 */
final class HallSchemaSectorGeometryTest extends TestCase
{
    use DatabaseTransactions;

    private Hall $hall;

    private HallService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $organizationId = DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Sector geometry ' . Str::random(10),
            'slug' => 'sector-geometry-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'settings_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $venueId = DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Geometry venue',
            'slug' => 'geometry-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hallId = DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Geometry hall',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->hall = Hall::query()->findOrFail($hallId);
        $this->service = app(HallService::class);
    }

    /**
     * Места в формате редактора: локальные координаты внутри группы сектора.
     *
     * @return list<array{id: string, row: int, number: int, kind: string, x: int, y: int}>
     */
    private function localGrid(int $rows, int $perRow, string $tag): array
    {
        $seats = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($n = 0; $n < $perRow; $n++) {
                $seats[] = [
                    'id' => "$tag-$r-$n",
                    'row' => $r + 1,
                    'number' => $n + 1,
                    'kind' => 'standard',
                    // Локально: 22 px по X (место 16 + зазор 6), 28 px по Y.
                    'x' => $n * 22,
                    'y' => $r * 28,
                ];
            }
        }

        return $seats;
    }

    /**
     * Два сектора, разнесённых по холсту, но с ОДИНАКОВЫМИ локальными координатами
     * мест — ровно то, что отдаёт редактор.
     *
     * @return array<string, mixed>
     */
    private function twoSectorsEditorFormat(): array
    {
        return [
            'version' => '1.0',
            'canvas' => ['width' => 900, 'height' => 620],
            'sectors' => [
                [
                    'id' => 'sec-a', 'name' => 'Партер', 'x' => 80, 'y' => 140,
                    'priceMinor' => 350000, 'type' => 'seated', 'shape' => 'grid',
                    'seats' => $this->localGrid(4, 6, 'a'),
                ],
                [
                    'id' => 'sec-b', 'name' => 'Балкон', 'x' => 600, 'y' => 140,
                    'priceMinor' => 180000, 'type' => 'seated', 'shape' => 'grid',
                    'seats' => $this->localGrid(4, 6, 'b'),
                ],
            ],
        ];
    }

    public function test_two_sectors_placed_apart_are_not_reported_as_overlapping(): void
    {
        $draft = $this->service->createSchemaDraft($this->hall, $this->twoSectorsEditorFormat(), 1);

        self::assertSame('draft', $draft->status);
        self::assertSame(2, count($draft->schema_json['sectors']));
    }

    public function test_sectors_that_really_overlap_on_canvas_are_rejected(): void
    {
        $payload = $this->twoSectorsEditorFormat();
        // Второй сектор сдвигаем на первый: места совпадают на холсте.
        $payload['sectors'][1]['x'] = 80;
        $payload['sectors'][1]['y'] = 140;

        try {
            $this->service->createSchemaDraft($this->hall, $payload, 1);
            self::fail('Ожидалась ValidationError: сектора действительно наложены');
        } catch (ValidationError $e) {
            self::assertArrayHasKey('sectors.1.seats.0.position', $e->errors);
        }
    }

    public function test_inventory_format_keeps_sector_offsets(): void
    {
        $draft = $this->service->createSchemaDraft($this->hall, $this->twoSectorsEditorFormat(), 1);

        $sectors = $draft->toInventoryFormat();
        self::assertCount(2, $sectors);

        $xs = [];
        foreach ($sectors as $sector) {
            $row = $sector['rows'][0];
            $xs[$sector['name']] = array_map(
                static fn (array $seat): int => (int) $seat['x'],
                $row['seats'],
            );
        }

        // Партер: (80 + 0..110) / 900 * 60 → 5..13
        self::assertSame(5, min($xs['Партер']));
        self::assertSame(13, max($xs['Партер']));
        // Балкон: (600 + 0..110) / 900 * 60 → 40..47 — без смещения сектора он
        // получил бы те же 5..13 и лёг бы на партер.
        self::assertSame(40, min($xs['Балкон']));
        self::assertSame(47, max($xs['Балкон']));

        self::assertGreaterThan(max($xs['Партер']), min($xs['Балкон']));
    }

    public function test_banquet_table_sector_becomes_sellable_seats(): void
    {
        // Сектор-стол: кольцо из 8 мест, все в ряду 1 (§47 «стол с местами»).
        $seats = [];
        for ($i = 0; $i < 8; $i++) {
            $angle = -M_PI / 2 + ($i * 2 * M_PI) / 8;
            $seats[] = [
                'id' => "tbl-$i",
                'row' => 1,
                'number' => $i + 1,
                'kind' => 'standard',
                'x' => (int) round(74 + 48 * cos($angle) - 8),
                'y' => (int) round(74 + 48 * sin($angle) - 8),
            ];
        }

        $draft = $this->service->createSchemaDraft($this->hall, [
            'version' => '1.0',
            'canvas' => ['width' => 900, 'height' => 700],
            'sectors' => [[
                'id' => 'sec-table-1', 'name' => 'Стол 1', 'x' => 120, 'y' => 160,
                'priceMinor' => 900000, 'type' => 'seated', 'shape' => 'table',
                'seats' => $seats,
            ]],
            'staticObjects' => [
                ['id' => 'st-1', 'kind' => 'stage', 'x' => 100, 'y' => 20, 'width' => 300, 'height' => 60],
            ],
        ], 1);

        // Стол продаётся как обычный сектор: одно кольцо = один ряд из N мест.
        self::assertTrue(HallService::payloadHasSeats($draft->schema_json));

        $sectors = $draft->toInventoryFormat();
        self::assertCount(1, $sectors);
        self::assertSame('Стол 1', $sectors[0]['name']);
        self::assertCount(1, $sectors[0]['rows']);
        self::assertCount(8, $sectors[0]['rows'][0]['seats']);
        self::assertSame(900000, $sectors[0]['rows'][0]['price_amount']);

        $numbers = array_map(
            static fn (array $seat): string => (string) $seat['number'],
            $sectors[0]['rows'][0]['seats'],
        );
        self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8'], $numbers);
    }

    public function test_decor_only_schema_is_refused_with_a_stable_code(): void
    {
        // Столы и сцена — только декорации: продавать нечего.
        $draft = $this->service->createSchemaDraft($this->hall, [
            'version' => '1.0',
            'canvas' => ['width' => 900, 'height' => 620],
            'sectors' => [],
            'staticObjects' => [
                ['id' => 'd1', 'kind' => 'table', 'x' => 200, 'y' => 200, 'width' => 120, 'height' => 80],
                ['id' => 'd2', 'kind' => 'stage', 'x' => 300, 'y' => 40, 'width' => 300, 'height' => 60],
            ],
        ], 1);

        self::assertFalse(HallService::payloadHasSeats($draft->schema_json));

        try {
            $this->service->publishSchemaVersion((int) $draft->id, 1, (string) $this->hall->public_id);
            self::fail('Ожидалась ValidationError: в схеме нет продаваемых мест');
        } catch (ValidationError $e) {
            // Клиент ветвится на `code`, а не на текст: код обязан доехать.
            self::assertSame('SCHEMA_EMPTY_PAYLOAD', $e->errorCode);
            self::assertSame(422, $e->status);
            self::assertArrayHasKey('schema', $e->errors);
        }
    }
}
