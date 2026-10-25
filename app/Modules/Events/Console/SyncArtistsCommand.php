<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Support\PublicationStatus;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Models\Artist;

/**
 * Сводит артистов из `event_artists` в канонические записи `artists`.
 *
 * ЗАЧЕМ КОМАНДА, А НЕ АВТОМАТИЧЕСКОЕ СОЗДАНИЕ ПРИ СОХРАНЕНИИ СОБЫТИЯ.
 * Сведение написаний — операция, которую нужно ВИДЕТЬ до применения: слишком
 * агрессивная нормализация склеит двух разных артистов в одну страницу, и
 * разлепить их придётся руками, уже после того как адрес попал в индекс.
 * `--dry-run` показывает список слияний до записи; сохранение события такого
 * показать не может, потому что оно происходит в момент, когда администратор
 * думает о другом.
 *
 * ЧТО КОМАНДА ДЕЛАЕТ
 *   1. Читает `event_artists` вместе с их событиями — только публично видимыми
 *      (`EventStatus::publiclyVisible()`). Событие в черновике страницы артиста
 *      не создаёт: порог существования страницы — хотя бы одно видимое событие.
 *   2. Группирует строки по `Artist::normalizeName()`. Внутри группы выбирает
 *      САМОЕ ЧАСТОЕ написание как отображаемое имя: так «Би-2» побеждает
 *      случайную опечатку, а не наоборот.
 *   3. Создаёт недостающих артистов и синхронизирует связь `artist_event`.
 *
 * ЧЕГО КОМАНДА НЕ ДЕЛАЕТ — И ЭТО ГЛАВНОЕ ПРАВИЛО.
 *   Она НИКОГДА не перезаписывает поля существующего артиста: ни `name`, ни
 *   `bio`, ни `genre`, ни `photo`, ни `slug`. Синхронизация обнаруживает и
 *   связывает; правит — человек. Иначе первое же исправление биографии в
 *   админке было бы затёрто следующим прогоном, а смена `slug` оборвала бы
 *   внешние ссылки на уже проиндексированную страницу. Единственное, что
 *   команда обновляет у существующей записи, — состав связи с событиями: она
 *   для этого и нужна.
 *
 * ИДЕМПОТЕНТНОСТЬ. Повторный прогон не создаёт дублей: артист ищется по
 * `normalized_name` (UNIQUE в схеме), а `sync()` ЗАМЕНЯЕТ набор событий, а не
 * добавляется к нему. Поэтому команду безопасно ставить в cron и запускать
 * после массового импорта.
 */
final class SyncArtistsCommand extends Command
{
    protected $signature = 'artists:sync
                            {--dry-run : Показать, что изменится, и ничего не записывать}';

    protected $description = 'Сводит написания артистов из event_artists в канонические записи artists и связывает их с событиями';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $groups = $this->collectGroups();

        if ($groups === []) {
            $this->info('В event_artists нет строк с публично видимыми событиями — синхронизировать нечего.');

            return self::SUCCESS;
        }

        $created = 0;
        $linked = 0;

        foreach ($groups as $key => $group) {
            $display = $this->dominantName($group['names']);

            $artist = Artist::query()->where('normalized_name', $key)->first();

            if ($artist === null) {
                $created++;

                if (! $dryRun) {
                    $artist = Artist::query()->create([
                        'slug' => $this->uniqueSlug($display, $key),
                        'name' => $display,
                        'normalized_name' => $key,
                        'genre' => $group['genre'],
                        'bio' => $group['bio'],
                        'photo' => $group['photo'],
                        'social_links' => $group['social_links'],
                        'status' => PublicationStatus::ACTIVE,
                    ]);
                }
            }

            $linked += count($group['events']);

            if ($artist !== null && ! $dryRun) {
                // `sync()` — замена набора, а не добавление: событие, у которого
                // артист больше не указан, из связи уходит. Иначе страница
                // артиста со временем накапливала бы события, к которым он
                // отношения не имеет, и заметить это было бы нечем.
                $artist->events()->sync($group['events']);
            }
        }

        $this->report($groups, $created, $linked, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Строки `event_artists`, сгруппированные по ключу слияния.
     *
     * `whereNull('events.deleted_at')` выписан руками, хотя у модели `Event`
     * есть `SoftDeletes`: запрос идёт через `DB::table()`, а сырой конструктор
     * про трейт не знает. Без него мягко удалённое событие продолжало бы
     * создавать артистам связь, то есть на странице артиста появилось бы
     * событие, которого нет ни в каталоге, ни в карте сайта.
     *
     * @return array<string, array{
     *     names: array<string, int>,
     *     genre: ?string,
     *     bio: ?string,
     *     photo: ?string,
     *     social_links: ?string,
     *     events: array<int, array{is_headliner: bool, sort_order: int}>
     * }>
     */
    private function collectGroups(): array
    {
        $rows = DB::table('event_artists')
            ->join('events', 'events.id', '=', 'event_artists.event_id')
            ->whereIn('events.status', EventStatus::publiclyVisible())
            ->whereNull('events.deleted_at')
            ->orderBy('event_artists.id')
            ->get([
                'event_artists.event_id as event_id',
                'event_artists.name as name',
                'event_artists.genre as genre',
                'event_artists.bio as bio',
                'event_artists.photo as photo',
                'event_artists.social_links as social_links',
                'event_artists.is_headliner as is_headliner',
                'event_artists.sort_order as sort_order',
            ]);

        $groups = [];

        foreach ($rows as $row) {
            $name = trim((string) $row->name);

            if ($name === '') {
                continue;
            }

            $key = Artist::normalizeName($name);

            if ($key === '') {
                continue;
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'names' => [],
                    'genre' => null,
                    'bio' => null,
                    'photo' => null,
                    'social_links' => null,
                    'events' => [],
                ];
            }

            $groups[$key]['names'][$name] = ($groups[$key]['names'][$name] ?? 0) + 1;

            // Первое непустое значение побеждает: строки идут по `id`, то есть
            // от старых к новым, и перезапись более новым значением затирала бы
            // заполненную карточку пустой.
            foreach (['genre', 'bio', 'photo', 'social_links'] as $field) {
                $value = $row->{$field};

                if ($groups[$key][$field] === null && $value !== null && $value !== '') {
                    $groups[$key][$field] = (string) $value;
                }
            }

            $eventId = (int) $row->event_id;

            // Один артист мог быть указан в событии дважды (например, как
            // участник и как хедлайнер). Для связи это одно ребро, и признак
            // хедлайнера берётся по ИЛИ: если он хедлайнер хотя бы в одной
            // строке, он хедлайнер.
            $isHeadliner = (bool) $row->is_headliner;
            $previous = $groups[$key]['events'][$eventId] ?? null;

            $groups[$key]['events'][$eventId] = [
                'is_headliner' => $isHeadliner || ($previous['is_headliner'] ?? false),
                'sort_order' => $previous['sort_order'] ?? (int) $row->sort_order,
            ];
        }

        return $groups;
    }

    /**
     * Отображаемое имя группы — самое частое написание.
     *
     * При равенстве частот побеждает то, что встречается в более раннем
     * событии: строки приходят в порядке `id`, поэтому порядок ключей в массиве
     * — это порядок первого появления, и выбор получается детерминированным.
     * Без этого правила повторный прогон на тех же данных мог бы выбрать другое
     * написание, и «синхронизация» меняла бы имя на каждом запуске.
     *
     * @param  array<string, int>  $names
     */
    private function dominantName(array $names): string
    {
        $best = '';
        $bestCount = -1;

        foreach ($names as $name => $count) {
            if ($count > $bestCount) {
                $best = (string) $name;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Свободный адрес для артиста: базовый slug, при занятости — с суффиксом.
     *
     * Занятость проверяется по ЧУЖОМУ `normalized_name`: у самого артиста адрес
     * уже есть, и он не должен считаться конфликтом с собой. Адрес выбирается
     * один раз при создании и больше не меняется — это и есть причина, по
     * которой команда не трогает `slug` у существующей записи.
     */
    private function uniqueSlug(string $name, string $normalizedName): string
    {
        $base = Artist::slugFor($name);
        $candidate = $base;
        $suffix = 2;

        while (Artist::query()
            ->where('slug', $candidate)
            ->where('normalized_name', '!=', $normalizedName)
            ->exists()
        ) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @param  array<string, array{names: array<string, int>, events: array<int, mixed>}>  $groups
     */
    private function report(array $groups, int $created, int $linked, bool $dryRun): void
    {
        $merges = array_filter($groups, static fn (array $g): bool => count($g['names']) > 1);

        if ($merges !== []) {
            $this->newLine();
            $this->line('  Слияния написаний (проверьте, что это действительно один артист):');
            $this->newLine();

            foreach ($merges as $key => $group) {
                $variants = array_map(
                    static fn (string $n, int $c): string => sprintf('«%s» ×%d', $n, $c),
                    array_keys($group['names']),
                    array_values($group['names']),
                );

                $this->line(sprintf('    %s  ←  %s', $key, implode(', ', $variants)));
            }

            $this->newLine();
        }

        $this->line(sprintf(
            '  Групп: %d, артистов создано: %d, связей с событиями: %d',
            count($groups),
            $created,
            $linked,
        ));

        if ($dryRun) {
            $this->warn('  --dry-run: ничего не записано. Запустите без флага, чтобы применить.');
        }
    }
}
