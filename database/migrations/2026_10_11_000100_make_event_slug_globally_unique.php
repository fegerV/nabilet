<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Глобальная уникальность `events.slug` (SEO-аудит P-03).
 *
 * ДЕФЕКТ. Slug был уникален только внутри организации
 * (`UNIQUE KEY uq_events_org_slug (organization_id, slug)`), а публичный адрес
 * организации не содержит вовсе — `/event/{slug}/{publicId}`. Разрешение
 * тенанта при этом устроено как «первая организация по `id`
 * (`EventRepository::resolveOrganizationId()`). Пока организация одна, разницы
 * нет. Со второй — один и тот же slug начинают носить два события из разных
 * организаций, и:
 *
 *   • `/event/novyj-god/…` открывает то из них, чья организация оказалась
 *     первой по `id`, — то есть произвольное;
 *   • канонические адреса двух организаций физически неразличимы, и обе
 *     страницы претендуют на один URL в индексе поисковика;
 *   • `redirects.source` (таблица редиректов) объявлен глобально уникальным
 *     (`uq_redirect_source`), то есть переименование slug в одной организации
 *     затирало бы редирект другой — таблица уже предполагала глобальность,
 *     которой у slug не было.
 *
 * ПОЧЕМУ ГЛОБАЛЬНЫЙ ИНДЕКС, А НЕ ПРЕФИКС ОРГАНИЗАЦИИ. Полное решение —
 * `/{orgSlug}/event/…` или отдельный домен на организацию — меняет публичный
 * контракт URL и потому делается вместе с подключением второго клиента
 * (аудит P-03/P-12). Промежуточное состояние «индекс глобальный» выбрано
 * потому, что оно переводит молчаливый дефект в явный: коллизия теперь
 * обнаруживается в момент создания события (422 от валидатора), а не через
 * месяц на чужой странице. Обратная сторона — вторая организация не сможет
 * взять занятый slug; это и есть цена, которую платят до появления
 * организационных URL.
 *
 * FAIL-CLOSED. Если в базе УЖЕ есть два события с одним slug, миграция
 * останавливается с перечислением конфликтов, а не переименовывает их сама.
 * Slug — это публичный адрес: переименование оборвало бы внешние ссылки и
 * позиции, и решать, какой из двух адресов оставить, должен владелец данных.
 */
return new class extends Migration
{
    /** Прежний составной индекс: организация + slug. */
    private const OLD_INDEX = 'uq_events_org_slug';

    /** Новый индекс: slug глобально. */
    private const NEW_INDEX = 'uq_events_slug';

    public function up(): void
    {
        $this->assertNoDuplicateSlugs();

        Schema::table('events', function (Blueprint $table): void {
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
        Schema::table('events', function (Blueprint $table): void {
            if ($this->hasIndex(self::NEW_INDEX)) {
                $table->dropUnique(self::NEW_INDEX);
            }

            if (!$this->hasIndex(self::OLD_INDEX)) {
                $table->unique(['organization_id', 'slug'], self::OLD_INDEX);
            }
        });
    }

    /**
     * Один slug — одно событие, включая мягко удалённые.
     *
     * `deleted_at` здесь не фильтруется НАМЕРЕННО: индексу он тоже безразличен,
     * и строка с заполненным `deleted_at` занимает значение в UNIQUE точно так
     * же. Проверка без этого условия пропустила бы конфликт, и миграция упала
     * бы уже на `ALTER TABLE` — с невнятной ошибкой MySQL вместо списка
     * конфликтующих событий.
     */
    private function assertNoDuplicateSlugs(): void
    {
        $duplicates = DB::select(
            'SELECT slug, COUNT(*) AS c FROM events GROUP BY slug HAVING c > 1 LIMIT 10',
        );

        if ($duplicates === []) {
            return;
        }

        $listed = implode(', ', array_map(
            static fn ($row): string => sprintf('«%s» (%d)', (string) $row->slug, (int) $row->c),
            $duplicates,
        ));

        throw new RuntimeException(
            'Cannot make events.slug globally unique: duplicates already exist — ' . $listed
            . '. Публичный адрес события не содержит организацию, поэтому такие пары '
            . 'открывают произвольное из двух событий. Переименуйте одно из них '
            . '(и заведите редирект со старого адреса) перед запуском этой миграции.',
        );
    }

    private function hasIndex(string $name): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.statistics'
            . ' WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            ['events', $name],
        ) !== null;
    }
};
