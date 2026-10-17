<?php

declare(strict_types=1);

namespace Tests\Feature\Venues;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Database\Seeders\NeftyanikHallSeeder;
use Tests\TestCase;

/**
 * Каталог: зал «Нефтяника» с готовой схемой переживает переустановку.
 *
 * Год назад вся схема жила только в dev-базе; reinstall on hosting означал
 * бы ручной повтор всей правки в редакторе. Сидер ставит зал вместе с
 * опубликованной версией (1073 места) одним блоком, идемпотентно.
 */
final class NeftyanikHallSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Организация + автор версии: сидер берёт первую организацию и первого
        // пользователя (после NabiletAdminSeeder это admin).
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
        (new NeftyanikHallSeeder)->run();
    }

    public function test_seeds_venue_hall_and_published_schema(): void
    {
        $this->seedOnce();

        $venue = DB::table('venues')->where('slug', 'dvorec-iskusstv-neftyanik')->first();
        $this->assertNotNull($venue, 'площадка должна быть в каталоге');

        $hall = DB::table('halls')->where('venue_id', $venue->id)->where('name', 'Концертно-театральный зал')->first();
        $this->assertNotNull($hall, 'зал должен быть создан');

        $version = DB::table('hall_schema_versions')->where('hall_id', $hall->id)->first();
        $this->assertNotNull($version, 'версия схемы должна существовать');
        $this->assertSame('published', $version->status);

        $schema = json_decode((string) $version->schema_json, true);

        $this->assertSame(1073, array_sum(array_map(
            static fn (array $sector): int => count($sector['seats'] ?? []),
            $schema['sectors'],
        )));

        // Цены сняты с витрины Яндекса: 2500 (балкон) … 16000 (приставные места).
        // У большинства мест priceMinor не записан (экспорт держит только
        // переопределения): цену восстанавливаем цепочкой место → ряд →
        // сектор, ровно как это делает материализация инвентаря.
        $prices = [];

        foreach ($schema['sectors'] as $sector) {
            foreach ($sector['seats'] ?? [] as $seat) {
                $price = $seat['priceMinor']
                    ?? ($sector['rowPrices'][(string) $seat['row']] ?? null)
                    ?? ($sector['rowPrices'][(int) $seat['row']] ?? null)
                    ?? ($sector['priceMinor'] ?? null);

                if (is_numeric($price) && $price > 0) {
                    $prices[] = (int) $price;
                }
            }
        }

        $this->assertNotEmpty($prices);
        $this->assertSame(250000, min($prices));
        $this->assertSame(1600000, max($prices));
    }

    public function test_is_idempotent_and_never_overwrites_editor_changes(): void
    {
        $this->seedOnce();

        $hall = DB::table('halls')->where('name', 'Концертно-театральный зал')->first();

        $versionsBefore = (int) DB::table('hall_schema_versions')->where('hall_id', $hall->id)->count();

        $this->seedOnce();

        $this->assertSame(
            $versionsBefore,
            (int) DB::table('hall_schema_versions')->where('hall_id', $hall->id)->count(),
            'повторный сидер не должен создавать новую версию схемы'
        );

        $this->assertSame(
            1,
            (int) DB::table('halls')->where('venue_id', $hall->venue_id)->count(),
            'повторный сидер не должен создавать дубликат зала'
        );
    }
}
