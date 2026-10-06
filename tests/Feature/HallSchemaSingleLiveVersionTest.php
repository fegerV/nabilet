<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Tests\TestCase;

/**
 * Инвариант «одна published и одна draft версия на зал» держится БД, а не только
 * сервисом.
 *
 * Повод — аудит схемы: до этой миграции инвариант обеспечивал исключительно
 * `HallService` + `lockForUpdate()`. Любой прямой UPDATE, импорт дампа или
 * будущий админ-скрипт мог оставить зал с двумя published-версиями, после чего
 * витрина продавала бы то одну схему, то другую (`currentSchemaVersion()`
 * выбирает первую попавшуюся), а редактор открывал бы произвольный черновик.
 */
final class HallSchemaSingleLiveVersionTest extends TestCase
{
    use DatabaseTransactions;

    private Hall $hall;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();
        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Single live ' . Str::random(10),
            'slug' => 'single-live-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'settings_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $venueId = (int) DB::table('venues')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'name' => 'Single live venue',
            'slug' => 'single-live-' . Str::lower(Str::random(12)),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hallId = (int) DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $venueId,
            'name' => 'Single live hall',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->hall = Hall::query()->findOrFail($hallId);
    }

    private function makeVersion(int $version, string $status): HallSchemaVersion
    {
        return HallSchemaVersion::create([
            'hall_id' => $this->hall->id,
            'version' => $version,
            'revision' => 1,
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
            'schema_json' => ['version' => '1.0', 'sectors' => []],
        ]);
    }

    public function test_generated_columns_express_the_invariant(): void
    {
        $this->makeVersion(1, 'published');
        $this->makeVersion(2, 'draft');
        $this->makeVersion(3, 'archived');

        $rows = DB::table('hall_schema_versions')
            ->where('hall_id', $this->hall->id)
            ->orderBy('version')
            ->get(['version', 'status', 'published_hall_id', 'draft_hall_id']);

        // У «живых» строк в генерируемой колонке стоит hall_id, у остальных NULL —
        // именно поэтому UNIQUE допускает любое число archived-версий.
        self::assertSame($this->hall->id, (int) $rows[0]->published_hall_id);
        self::assertNull($rows[0]->draft_hall_id);

        self::assertSame($this->hall->id, (int) $rows[1]->draft_hall_id);
        self::assertNull($rows[1]->published_hall_id);

        self::assertNull($rows[2]->published_hall_id);
        self::assertNull($rows[2]->draft_hall_id);
    }

    public function test_second_published_version_for_the_same_hall_is_rejected(): void
    {
        $this->makeVersion(1, 'published');

        $this->expectException(QueryException::class);
        $this->makeVersion(2, 'published');
    }

    public function test_second_draft_for_the_same_hall_is_rejected(): void
    {
        $this->makeVersion(1, 'draft');

        $this->expectException(QueryException::class);
        $this->makeVersion(2, 'draft');
    }

    public function test_archived_versions_are_not_limited(): void
    {
        $this->makeVersion(1, 'archived');
        $this->makeVersion(2, 'archived');
        $this->makeVersion(3, 'archived');

        self::assertSame(3, DB::table('hall_schema_versions')->where('hall_id', $this->hall->id)->count());
    }

    public function test_publishing_after_archiving_the_previous_version_is_allowed(): void
    {
        $first = $this->makeVersion(1, 'published');

        // Штатный путь публикации: прежняя версия уходит в archived.
        $first->update(['status' => 'archived']);

        $second = $this->makeVersion(2, 'published');

        self::assertSame('published', $second->fresh()->status);
        self::assertSame('archived', $first->fresh()->status);
    }

    public function test_different_halls_each_get_their_own_published_version(): void
    {
        $now = now();
        $otherHallId = (int) DB::table('halls')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'venue_id' => $this->hall->venue_id,
            'name' => 'Another hall ' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->makeVersion(1, 'published');
        HallSchemaVersion::create([
            'hall_id' => $otherHallId,
            'version' => 1,
            'revision' => 1,
            'status' => 'published',
            'published_at' => $now,
            'schema_json' => ['version' => '1.0', 'sectors' => []],
        ]);

        self::assertSame(2, DB::table('hall_schema_versions')->where('status', 'published')->count());
    }
}
