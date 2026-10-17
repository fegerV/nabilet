<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nabilet\Modules\Venues\Halls\Services\HallService;
use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\Venue;

/**
 * Дворец искусств «Нефтяник» (Сургут) — зал с готовой схемой.
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ СИДЕР, КОГДА ЕСТЬ SurgutVenuesSeeder
 *   Каталог площадок сеет ТОЛЬКО строку `venues` (см. докблок SurgutVenuesSeeder:
 *   «ни залов, ни схем»). «Нефтяник» — исключение по договорённости: его схема
 *   зала с местами уже снята с витрины Яндекса, адаптирована под наш редактор и
 *   проверена (1073 места, 4 сектора, цены по рядам), а событие и сцену обещали
 *   завести позже. Схема — главный актив площадки, и терять её при переезде на
 *   хостинг или при `migrate:fresh` на dev-машине обидно: поэтому сидер ставит
 *   зал вместе с опубликованной версией схемы одним блоком.
 *
 * ИДЕМПОТЕНТНОСТЬ
 *   Ключ — (venue_id, name зала). Если зал уже существует и у него есть хотя бы
 *   одна версия схемы — сидер молча выходит: правки из редактора не
 *   перезатираются, повторный `db:seed --force` ничего не ломает.
 *
 * ПОРЯДОК В DatabaseSeeder
 *   После SurgutVenuesSeeder (нужна площадка) и NabiletAdminSeeder (нужен
 *   пользователь-автор версии). Оба в цепочке раньше; при отдельном запуске
 *   `db:seed --class=...` сидер самодостаточен: недостающую площадку создаёт
 *   сам, автором версии берёт первого пользователя.
 */
final class NeftyanikHallSeeder extends Seeder
{
    public const VENUE_SLUG = 'dvorec-iskusstv-neftyanik';
    public const VENUE_NAME = 'Дворец искусств «Нефтяник»';
    public const HALL_NAME = 'Концертно-театральный зал';

    public function run(): void
    {
        if (! Schema::hasTable('hall_schema_versions')) {
            $this->command?->warn('Neftyanik hall skipped: migrations not run yet.');

            return;
        }

        $hall = Hall::query()
            ->where('venue_id', $this->resolveVenueId())
            ->where('name', self::HALL_NAME)
            ->first();

        if ($hall !== null && $hall->schemaVersions()->exists()) {
            // Правки из редактора не перезатираем — то же правило, что у
            // SurgutVenuesSeeder для описаний площадок.
            $this->command?->info('Neftyanik hall already has a schema version — skipped.');

            return;
        }

        $payload = $this->schemaPayload();

        if ($hall === null) {
            $hall = $this->createHall();
        }

        $userId = (int) (DB::table('users')->orderBy('id')->value('id') ?? 1);

        $version = app(HallService::class)->createSchemaDraft($hall, $payload, $userId);
        app(HallService::class)->publishSchemaVersion((int) $version->id, $userId, (string) $hall->public_id);

        $this->command?->info(sprintf(
            'Neftyanik hall: schema v%d published (%d sectors, %d seats).',
            $version->id,
            count($payload['sectors']),
            array_sum(array_map(static fn (array $s): int => count($s['seats'] ?? []), $payload['sectors'])),
        ));
    }

    /** Площадка по слагу каталога; на свежей базе создаётся строкой каталога. */
    private function resolveVenueId(): int
    {
        $venue = Venue::query()->where('slug', self::VENUE_SLUG)->first();

        if ($venue !== null) {
            return (int) $venue->id;
        }

        $organizationId = (int) (DB::table('organizations')->orderBy('id')->value('id') ?? 0);

        return (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => self::VENUE_NAME,
            'slug' => self::VENUE_SLUG,
            'description' => 'Крупнейшая концертная площадка Сургута: большая сцена, камерный зал, гастрольные мероприятия и фестивали.',
            'country' => 'RU',
            'region' => 'Ханты-Мансийский автономный округ — Югра',
            'city' => 'Сургут',
            'address' => 'Энгельса проспект, 5',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createHall(): Hall
    {
        return app(HallService::class)->createHall([
            'venue_id' => $this->resolveVenueId(),
            'name' => self::HALL_NAME,
            'description' => 'Концертно-театральный зал Дворца искусств «Нефтяник»: партер, приставные места, бельэтаж и балкон.',
            'city' => 'Сургут',
            'address' => 'Энгельса проспект, 5',
            'status' => 'active',
        ]);
    }

    /** Адаптированная под редактор схема (экспорт previous session, проверена валидатором). */
    private function schemaPayload(): array
    {
        $path = __DIR__ . '/data/neftyanik-hall-schema.json';

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("Neftyanik schema payload is not valid JSON: {$path}");
        }

        return $decoded;
    }
}
