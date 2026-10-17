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
 * Базовый сидер зала вместе с опубликованной схемой.
 *
 * ЗАЧЕМ. Инвентарь (места и их цены) материализуется из `hall_schema_versions`
 * при создании сеанса: без схемы зал не продает. Залы-«опоры» витрины
 * («Нефтяник», «Вавилон») с снятыми и адаптированными схемами должны
 * появляться на свежей установке сами, а не через ручную правку в редакторе.
 *
 * ИДЕМПОТЕНТНОСТЬ
 *   Ключ — (venue_id, имя зала). Есть версия схемы — сидер молча выходит:
 *   правки из редактора не перезатираются (то же правило, что и у
 *   SurgutVenuesSeeder для описаний площадок).
 *
 * ПОРЯДОК В DatabaseSeeder
 *   После SurgutVenuesSeeder (площадка) и NabiletAdminSeeder (автор версии).
 *
 *data-файлы: `database/seeders/data/*-hall-schema.json` — редакторский format,
 * проходит HallService::validateSchemaPayload без изменений.
 */
abstract class HallSchemaSeeder extends Seeder
{
    abstract protected function venueSlug(): string;

    abstract protected function venueDisplayName(): string;

    abstract protected function venueAddress(): string;

    abstract protected function venueDescription(): string;

    abstract protected function hallName(): string;

    abstract protected function payloadFile(): string;

    public function run(): void
    {
        if (! Schema::hasTable('hall_schema_versions')) {
            $this->command?->warn(static::class . ' skipped: migrations not run yet.');

            return;
        }

        $hall = Hall::query()
            ->where('venue_id', $this->resolveVenueId())
            ->where('name', $this->hallName())
            ->first();

        if ($hall !== null && $hall->schemaVersions()->exists()) {
            $this->command?->info(static::class . ': schema version already exists — skipped.');

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
            '%s: schema v%d published (%d sectors, %d seats).',
            static::class,
            $version->id,
            count($payload['sectors']),
            array_sum(array_map(static fn (array $s): int => count($s['seats'] ?? []), $payload['sectors'])),
        ));
    }

    /** Площадка по слагу каталога; на свежей базе создаётся строкой каталога. */
    protected function resolveVenueId(): int
    {
        $venue = Venue::query()->where('slug', $this->venueSlug())->first();

        if ($venue !== null) {
            return (int) $venue->id;
        }

        $organizationId = (int) (DB::table('organizations')->orderBy('id')->value('id') ?? 0);

        return (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => $this->venueDisplayName(),
            'slug' => $this->venueSlug(),
            'description' => $this->venueDescription(),
            'country' => SurgutVenuesSeeder::COUNTRY,
            'region' => SurgutVenuesSeeder::REGION,
            'city' => SurgutVenuesSeeder::CITY,
            'address' => $this->venueAddress(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createHall(): Hall
    {
        return app(HallService::class)->createHall([
            'venue_id' => $this->resolveVenueId(),
            'name' => $this->hallName(),
            'description' => sprintf(
                '%s: %s. Схема зала адаптирована для редактора и используется сеансами витрины.',
                $this->venueDisplayName(),
                $this->hallName(),
            ),
            'city' => SurgutVenuesSeeder::CITY,
            'address' => $this->venueAddress(),
            'status' => 'active',
        ]);
    }

    /** Адаптированная под редактор схема (проверяется валидатором HallService). */
    private function schemaPayload(): array
    {
        $path = __DIR__ . '/' . $this->payloadFile();

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new \RuntimeException(static::class . ": schema payload is not valid JSON: {$path}");
        }

        return $decoded;
    }
}
