<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Nabilet\Modules\Venues\Models\HallTable;
use Tests\TestCase;

/**
 * Банкетный стол: геометрия из схемы зала попадает в `hall_tables`.
 *
 * Повод — аудит схемы БД: таблица `hall_tables` из ТЗ («схемы залов») не
 * заполнялась НИКОГДА — 0 строк во всех трёх базах, ни одного писателя, а
 * `Sector::hallTables()` не вызывался ниоткуда. Стол в редакторе — это сектор
 * `shape: 'table'` с кольцом мест, и вся его геометрия жила только внутри
 * JSON-payload'а. Теперь при генерации инвентаря она материализуется в БД.
 */
final class HallTableGeometryTest extends TestCase
{
    use DatabaseTransactions;

    private Hall $hall;

    private HallSchemaVersion $version;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $organizationId = DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Table geometry ' . Str::random(10),
            'slug' => 'table-geometry-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'settings_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $venueId = DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Table venue',
            'slug' => 'table-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hallId = DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Banquet hall',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->organizationId = $organizationId;
        $this->hall = Hall::query()->findOrFail($hallId);

        $this->version = HallSchemaVersion::create([
            'hall_id' => $this->hall->id,
            'version' => 1,
            'revision' => 1,
            'status' => 'published',
            'published_at' => $now,
            'schema_json' => $this->banquetSchema(),
        ]);
    }

    /**
     * Банкетный зал: два стола по 8 и 6 мест (кольцо), плюс сцена.
     *
     * @return array<string, mixed>
     */
    private function banquetSchema(): array
    {
        $table = function (string $name, int $count, int $offsetX, int $offsetY): array {
            $seats = [];
            $radius = 60;
            for ($i = 0; $i < $count; $i++) {
                $angle = -M_PI / 2 + ($i * 2 * M_PI) / $count;
                $seats[] = [
                    'id' => "$name-$i",
                    'row' => 1,
                    'number' => $i + 1,
                    'kind' => 'standard',
                    'x' => (int) round(80 + $radius * cos($angle)),
                    'y' => (int) round(80 + $radius * sin($angle)),
                ];
            }

            return [
                'id' => "sec-$name",
                'name' => $name,
                'x' => $offsetX,
                'y' => $offsetY,
                'priceMinor' => 900000,
                'type' => 'seated',
                'shape' => 'table',
                'rowPrices' => [],
                'seats' => $seats,
            ];
        };

        return [
            'version' => '1.0',
            'canvas' => ['width' => 900, 'height' => 620],
            'sectors' => [$table('Стол 1', 8, 100, 120), $table('Стол 2', 6, 500, 120)],
            'staticObjects' => [[
                'id' => 'stage-1', 'kind' => 'stage', 'x' => 300, 'y' => 20,
                'width' => 300, 'height' => 60, 'text' => 'Сцена',
            ]],
        ];
    }

    /**
     * Сессия для генерации инвентаря.
     *
     * Важно: `venue_id` / `hall_id` / `schema_version_id` живут на СЕССИИ, а не
     * на событии (`events` их не имеет вовсе) — генератор берёт зал и версию
     * схемы именно из сессии.
     */
    private function makeSession(): Session
    {
        $now = now();
        $eventId = DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'title' => 'Banquet event',
            'slug' => 'banquet-' . Str::lower(Str::random(12)),
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sessionId = DB::table('sessions')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'event_id' => $eventId,
            'venue_id' => $this->hall->venue_id,
            'hall_id' => $this->hall->id,
            'schema_version_id' => $this->version->id,
            'starts_at' => $now->copy()->addDays(7),
            'status' => 'on_sale',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Session::query()->findOrFail($sessionId);
    }

    public function test_to_inventory_format_carries_shape_and_table_geometry(): void
    {
        $out = $this->version->toInventoryFormat();

        self::assertCount(2, $out);
        self::assertSame('table', $out[0]['shape']);
        self::assertSame(8, $out[0]['table']['capacity']);
        self::assertSame(6, $out[1]['table']['capacity']);

        // Геометрия в координатах холста: центр кольца = смещение группы +
        // центр мест (места строились вокруг локальной точки (80, 80)).
        self::assertSame(180.0, round($out[0]['table']['cx'], 3));
        self::assertSame(200.0, round($out[0]['table']['cy'], 3));
        self::assertSame(60.0, round($out[0]['table']['ring'], 3));

        // Второй стол сдвинут по холсту на 400 px — и это видно в геометрии.
        self::assertSame(580.0, round($out[1]['table']['cx'], 3));
    }

    public function test_non_table_sectors_get_a_shape_but_no_table_geometry(): void
    {
        $out = $this->version->toInventoryFormat();
        foreach ($out as $sector) {
            self::assertSame('table', $sector['shape']);
        }

        $grid = (new HallSchemaVersion())->setAttribute('schema_json', [
            'canvas' => ['width' => 900, 'height' => 520],
            'sectors' => [[
                'name' => 'Партер', 'type' => 'seated', 'shape' => 'arc', 'priceMinor' => 100000,
                'seats' => [['id' => 'a', 'row' => 1, 'number' => 1, 'kind' => 'standard', 'x' => 10, 'y' => 10]],
            ]],
        ]);

        $converted = $grid->toInventoryFormat();
        self::assertSame('arc', $converted[0]['shape']);
        self::assertArrayNotHasKey('table', $converted[0]);
    }

    public function test_generate_from_schema_populates_hall_tables(): void
    {
        self::assertSame(0, HallTable::query()->count());

        $created = app(InventoryService::class)->generateFromSchema($this->version, $this->makeSession());

        // Продаются места за столами: 8 + 6 = 14 позиций.
        self::assertSame(14, $created);

        $tables = HallTable::query()->orderBy('id')->get();
        self::assertCount(2, $tables, 'Оба стола должны попасть в hall_tables');
        self::assertSame('Стол 1', $tables[0]->name);
        self::assertSame(8, $tables[0]->capacity);
        self::assertSame(6, $tables[1]->capacity);
        self::assertSame(180.0, round((float) $tables[0]->x + 60.0, 3));
        self::assertSame(60.0, round((float) $tables[0]->metadata_json['ring'], 3));
        self::assertSame(580.0, round((float) $tables[1]->metadata_json['cx'], 3));
        // Столы привязаны к секторам своего зала.
        self::assertSame(
            [$this->version->id, $this->version->id],
            DB::table('sectors')->whereIn('id', $tables->pluck('sector_id')->all())
                ->orderBy('id')->pluck('schema_version_id')->map(fn ($v) => (int) $v)->all(),
        );
    }

    public function test_regeneration_replaces_tables_without_duplicating(): void
    {
        $service = app(InventoryService::class);
        $session = $this->makeSession();

        $service->generateFromSchema($this->version, $session);
        self::assertSame(2, HallTable::query()->count());

        $service->generateFromSchema($this->version, $session);

        // Сектора пересоздаются, а hall_tables висит на них через
        // ON DELETE CASCADE — старые строки обязаны исчезнуть вместе с ними.
        self::assertSame(2, HallTable::query()->count(), 'Повторная генерация не должна удваивать столы');
    }
}
