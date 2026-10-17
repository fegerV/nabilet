<?php

declare(strict_types=1);

namespace Tests\Feature\Venues;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Database\Seeders\BabylonHallSeeder;
use Tests\TestCase;

/**
 * Каталог: клуб «Вавилон» со столовой схемой зала переживает переустановку.
 *
 * Схема — 62 стола-сектора (359 мест, 1000–1800 ₽), снята с витрины Яндекса
 * и адаптирована под редактор; повторный сидер не затирает правки.
 */
final class BabylonHallSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();

        DB::table('users')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'admin@example.test',
            'password' => 'x',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('organizations')->insert([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'nabilet-demo',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedOnce(): void
    {
        (new BabylonHallSeeder)->run();
    }

    public function test_seeds_venue_hall_and_published_table_scheme(): void
    {
        $this->seedOnce();

        $venue = DB::table('venues')->where('slug', 'vavilon')->first();
        $this->assertNotNull($venue, 'площадка должна быть в каталоге');

        $hall = DB::table('halls')->where('venue_id', $venue->id)->where('name', 'Основной зал')->first();
        $this->assertNotNull($hall, 'зал должен быть создан');

        $version = DB::table('hall_schema_versions')->where('hall_id', $hall->id)->first();
        $this->assertNotNull($version, 'версия схемы должна существовать');
        $this->assertSame('published', $version->status);

        $schema = json_decode((string) $version->schema_json, true);

        // 62 стола-сектора, 359 мест за столами.
        $this->assertSame(62, count($schema['sectors']));

        $places = 0;
        $prices = [];

        foreach ($schema['sectors'] as $sector) {
            $places += count($sector['seats'] ?? []);

            foreach ($sector['seats'] ?? [] as $seat) {
                $price = $seat['priceMinor'] ?? ($sector['priceMinor'] ?? null);

                if (is_numeric($price) && $price > 0) {
                    $prices[] = (int) $price;
                }
            }
        }

        $this->assertSame(359, $places);
        $this->assertSame(100000, min($prices));
        $this->assertSame(180000, max($prices));
    }

    public function test_is_idempotent(): void
    {
        $this->seedOnce();

        $hall = DB::table('halls')->where('name', 'Основной зал')->first();
        $versionsBefore = (int) DB::table('hall_schema_versions')->where('hall_id', $hall->id)->count();

        $this->seedOnce();

        $this->assertSame($versionsBefore, (int) DB::table('hall_schema_versions')->where('hall_id', $hall->id)->count());
        $this->assertSame(1, (int) DB::table('halls')->where('venue_id', $hall->venue_id)->count());
    }
}
