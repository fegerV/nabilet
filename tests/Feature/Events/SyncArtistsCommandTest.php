<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Models\Artist;
use Nabilet\Tests\Support\CatalogFixtures;
use Tests\TestCase;

/**
 * Команда `artists:sync` — сведение написаний артистов (стратегия SEO, B-1).
 *
 * ЗАЧЕМ ОТДЕЛЬНЫЙ ФАЙЛ. Страница артиста (`LandingArtistTest`) читает уже
 * готовые `artists` + `artist_event`; кто их создаёт — её не касается, и если бы
 * её тесты строились прогоном команды, любая правка команды роняла бы тесты
 * страницы по причине, к странице не относящейся. Здесь проверяется ИМЕННО
 * писатель, и проверяется на том, что делает его неочевидным:
 *
 *   • слияние написаний — «Би-2» и «БИ-2» обязаны стать ОДНОЙ записью. Это
 *     главный смысл таблицы `artists`, и на нём же лежит риск: слишком
 *     агрессивная нормализация склеит двух РАЗНЫХ артистов, и разлепить их
 *     придётся руками;
 *   • команда НИКОГДА не перезаписывает поля существующей записи. Первое же
 *     исправление биографии в админке было бы затёрто следующим прогоном, а
 *     смена `slug` оборвала бы внешние ссылки на уже проиндексированную
 *     страницу. Это главное правило команды, и оно закреплено тестом;
 *   • `--dry-run` не пишет НИЧЕГО: он существует ровно для того, чтобы список
 *     слияний можно было проверить ДО применения;
 *   • идемпотентность: повторный прогон не плодит дублей и не добавляет
 *     лишних связей.
 */
final class SyncArtistsCommandTest extends TestCase
{
    use CatalogFixtures;
    use RefreshDatabase;

    public function test_it_merges_spellings_of_one_name_into_a_single_artist(): void
    {
        $first = $this->seedCatalogEvent('Концерт 1', 'zal-sync-1', 'Москва');
        $second = $this->seedCatalogEvent('Концерт 2', 'zal-sync-2', 'Москва');

        // Источник — свободный текст, как его заполняет администратор.
        $this->seedEventArtist($first['event'], 'Би-2');
        $this->seedEventArtist($second['event'], 'БИ-2');

        $this->artisan('artists:sync')->assertExitCode(0);

        $this->assertSame(1, DB::table('artists')->count(), 'два написания дали двух артистов');

        $artist = DB::table('artists')->first();
        $this->assertSame(Artist::normalizeName('Би-2'), $artist->normalized_name);
        $this->assertSame(
            2,
            DB::table('artist_event')->where('artist_id', $artist->id)->count(),
            'события обоих написаний должны быть у одного артиста',
        );
    }

    public function test_it_keeps_distinct_artists_apart(): void
    {
        $first = $this->seedCatalogEvent('Концерт 1', 'zal-sync-3', 'Москва');
        $second = $this->seedCatalogEvent('Концерт 2', 'zal-sync-4', 'Москва');

        $this->seedEventArtist($first['event'], 'Би-2');
        $this->seedEventArtist($second['event'], 'Ночные снайперы');

        $this->artisan('artists:sync')->assertExitCode(0);

        // Положительный контроль к предыдущему тесту: команда не склеивает всё
        // подряд. Без него «один артист из двух написаний» было бы неотличимо от
        // «один артист из чего угодно».
        $this->assertSame(2, DB::table('artists')->count());
    }

    public function test_the_most_frequent_spelling_becomes_the_name(): void
    {
        $first = $this->seedCatalogEvent('Концерт 1', 'zal-sync-5', 'Москва');
        $second = $this->seedCatalogEvent('Концерт 2', 'zal-sync-6', 'Москва');
        $third = $this->seedCatalogEvent('Концерт 3', 'zal-sync-7', 'Москва');

        // «Би-2» встречается дважды, «БИ-2» — один раз: опечатка не должна
        // побеждать, а отображаемое имя берётся из более частого написания.
        $this->seedEventArtist($first['event'], 'Би-2');
        $this->seedEventArtist($second['event'], 'Би-2');
        $this->seedEventArtist($third['event'], 'БИ-2');

        $this->artisan('artists:sync')->assertExitCode(0);

        $this->assertSame('Би-2', DB::table('artists')->value('name'));
    }

    public function test_it_never_overwrites_an_existing_artist(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-sync-8', 'Москва');
        $this->seedEventArtist($event['event'], 'БИ-2');

        // Карточка заполнена человеком: своё написание, свой адрес, своя
        // биография. Следующий прогон синхронизации не имеет права это затирать.
        $artistId = $this->seedArtist(
            'Би-2',
            'gruppa-bi-2',
            [],
            bio: 'Биография, написанная руками.',
            genre: 'Рок',
        );

        $this->artisan('artists:sync')->assertExitCode(0);

        $artist = DB::table('artists')->where('id', $artistId)->first();
        $this->assertSame('Би-2', $artist->name, 'имя затёрто синхронизацией');
        $this->assertSame('gruppa-bi-2', $artist->slug, 'адрес затёрт — внешние ссылки оборвались бы');
        $this->assertSame('Биография, написанная руками.', $artist->bio, 'биография затёрта');
        $this->assertSame('Рок', $artist->genre);

        // Но связь с событием команда обязана проставить: она для этого и нужна.
        $this->assertSame(
            1,
            DB::table('artist_event')->where('artist_id', $artistId)->count(),
        );
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $first = $this->seedCatalogEvent('Концерт 1', 'zal-sync-9', 'Москва');
        $second = $this->seedCatalogEvent('Концерт 2', 'zal-sync-10', 'Москва');
        $this->seedEventArtist($first['event'], 'Би-2');
        $this->seedEventArtist($second['event'], 'БИ-2');

        $this->artisan('artists:sync --dry-run')->assertExitCode(0);

        // Ни одной записи: `--dry-run` существует ровно для того, чтобы список
        // слияний можно было проверить ДО применения.
        $this->assertSame(0, DB::table('artists')->count());
        $this->assertSame(0, DB::table('artist_event')->count());
    }

    public function test_it_is_idempotent(): void
    {
        $first = $this->seedCatalogEvent('Концерт 1', 'zal-sync-11', 'Москва');
        $second = $this->seedCatalogEvent('Концерт 2', 'zal-sync-12', 'Москва');
        $this->seedEventArtist($first['event'], 'Би-2');
        $this->seedEventArtist($second['event'], 'БИ-2');

        $this->artisan('artists:sync')->assertExitCode(0);
        $this->artisan('artists:sync')->assertExitCode(0);

        $this->assertSame(1, DB::table('artists')->count(), 'повторный прогон создал дубль');
        $this->assertSame(2, DB::table('artist_event')->count(), 'повторный прогон продублировал связи');
    }

    public function test_it_skips_events_that_are_not_publicly_visible(): void
    {
        $draft = $this->seedCatalogEvent('Черновик', 'zal-sync-13', 'Москва', status: EventStatus::DRAFT);
        $this->seedEventArtist($draft['event'], 'Би-2');

        $this->artisan('artists:sync')->assertExitCode(0);

        // Порог существования страницы артиста — хотя бы одно ПУБЛИЧНО видимое
        // событие. Артист, заведённый по черновику, не имел бы страницы, а
        // `artists:sync` завёл бы запись, которой не соответствует адрес.
        $this->assertSame(0, DB::table('artists')->count());
    }

    public function test_a_soft_deleted_event_does_not_create_an_artist(): void
    {
        $event = $this->seedCatalogEvent('Удалённый', 'zal-sync-14', 'Москва');
        $this->seedEventArtist($event['event'], 'Би-2');

        DB::table('events')->where('id', $event['event'])->update(['deleted_at' => now()]);

        $this->artisan('artists:sync')->assertExitCode(0);

        // Запрос идёт через `DB::table()`, а сырой конструктор про трейт
        // `SoftDeletes` не знает, — фильтр выписан вручную. Без него удалённое
        // событие продолжало бы создавать артистам связь, и на странице артиста
        // появилось бы событие, которого нет ни в каталоге, ни в карте сайта.
        $this->assertSame(0, DB::table('artists')->count());
    }

    public function test_a_headliner_in_any_row_makes_the_link_a_headliner(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-sync-15', 'Москва');

        // Один артист указан в событии дважды: как участник и как хедлайнер.
        // Для связи это ОДНО ребро (`uq_artist_event`), и признак берётся по ИЛИ.
        $this->seedEventArtist($event['event'], 'Би-2', false, 1);
        $this->seedEventArtist($event['event'], 'Би-2', true, 0);

        $this->artisan('artists:sync')->assertExitCode(0);

        $this->assertSame(1, DB::table('artist_event')->count(), 'одно событие — одно ребро');
        $this->assertSame(1, (int) DB::table('artist_event')->value('is_headliner'));
    }

    public function test_it_assigns_a_stable_slug_from_the_name(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-sync-16', 'Москва');
        $this->seedEventArtist($event['event'], 'Би-2');

        $this->artisan('artists:sync')->assertExitCode(0);

        // Адрес — публичный контракт: он выбирается один раз и больше не
        // меняется, иначе внешние ссылки на уже проиндексированную страницу
        // оборвались бы. Значение написано явно, а не через `Str::slug()`, —
        // иначе тест повторил бы поведение библиотеки транслитерации и ничего не
        // поймал бы при её обновлении.
        $this->assertSame('bi-2', DB::table('artists')->value('slug'));
    }
}
