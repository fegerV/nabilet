<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Глобальная уникальность `venues.slug` (SEO-аудит P-03/P-12).
 *
 * ЗАЧЕМ. Индекс `uq_venues_org_slug (organization_id, slug)` разрешал двум
 * организациям иметь площадки с одинаковым адресом, а публичный адрес площадки
 * организации не содержит. Ровно тот же дефект, что был у `events.slug` (см.
 * `2026_10_11_000100_make_event_slug_globally_unique.php`), и он становится
 * живым в тот момент, когда появляется страница `/venue/{slug}` — то есть в
 * этой же партии правок.
 *
 * ЧТО ВАЖНО: КОД УЖЕ ДАВНО ПРЕДПОЛАГАЛ ГЛОБАЛЬНУЮ УНИКАЛЬНОСТЬ, А ИНДЕКС — НЕТ.
 * `VenueController::store()` валидирует `unique:venues,slug` (без оговорки по
 * организации) и генерирует адрес циклом `while (Venue::where('slug', …)->exists())`,
 * `update()` — `unique:venues,slug,{id}`. То есть приложение вело себя так,
 * будто slug уникален глобально, а база это не обеспечивала: единственным
 * следствием расхождения был бы 500 от `INSERT` в тот день, когда валидацию
 * обошли бы (импорт, консольная команда, прямой SQL). Эта миграция приводит
 * схему в соответствие с тем, что код уже считает истиной.
 *
 * FAIL-CLOSED. При существующих дубликатах миграция останавливается с
 * перечислением конфликтов: slug — это публичный адрес, и выбирать, какой из
 * двух оставить, должна не миграция.
 */
return new class extends Migration
{
    /** Прежний составной индекс: организация + slug. */
    private const OLD_INDEX = 'uq_venues_org_slug';

    /** Новый индекс: slug глобально. */
    private const NEW_INDEX = 'uq_venues_slug';

    public function up(): void
    {
        $this->assertNoDuplicateSlugs();

        Schema::table('venues', function (Blueprint $table): void {
            if ($this->hasIndex(self::OLD_INDEX)) {
                $table->dropUnique(self::OLD_INDEX);
            }

            if (!$this->hasIndex(self::NEW_INDEX)) {
                $table->unique('slug', self::NEW_INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table): void {
            if ($this->hasIndex(self::NEW_INDEX)) {
                $table->dropUnique(self::NEW_INDEX);
            }

            if (!$this->hasIndex(self::OLD_INDEX)) {
                $table->unique(['organization_id', 'slug'], self::OLD_INDEX);
            }
        });
    }

    private function assertNoDuplicateSlugs(): void
    {
        $duplicates = DB::select(
            'SELECT slug, COUNT(*) AS c FROM venues GROUP BY slug HAVING c > 1 LIMIT 10',
        );

        if ($duplicates === []) {
            return;
        }

        $listed = implode(', ', array_map(
            static fn ($row): string => sprintf('«%s» (%d)', (string) $row->slug, (int) $row->c),
            $duplicates,
        ));

        throw new RuntimeException(
            'Cannot make venues.slug globally unique: duplicates already exist — ' . $listed
            . '. Публичный адрес площадки не содержит организацию, поэтому такие пары '
            . 'открывали бы произвольную из двух площадок. Переименуйте одну из них '
            . 'перед запуском этой миграции.',
        );
    }

    private function hasIndex(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            ['venues', $name],
        ) !== null;
    }
};
