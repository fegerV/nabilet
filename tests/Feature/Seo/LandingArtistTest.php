<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Events\Models\Artist;
use Nabilet\Tests\Support\CatalogFixtures;
use Tests\TestCase;

/**
 * Посадочная страница артиста — `/artist/{slug}` (стратегия SEO, B-1).
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ ФАЙЛ И ПОЧЕМУ ЭТО ГЛАВНАЯ ПРОВЕРКА B-1. Артист — единственная
 * из посадочных страниц, чья СУЩНОСТЬ НЕ СУЩЕСТВОВАЛА в схеме до этой работы:
 * площадка, город, категория и организатор читались из уже имевшихся таблиц, а
 * артист жил свободным текстом внутри события (`event_artists`). Поэтому здесь
 * проверяется не только страница, но и то, что она читает ПРАВИЛЬНЫЙ источник:
 *
 *   • страница собирается из `artists` + `artist_event`, а НЕ из `event_artists`.
 *     Это и есть разница между одной страницей артиста и страницей на каждое
 *     написание имени. Тест `..._does_not_split_by_spelling` закрепляет именно
 *     это: два события с «Би-2» и «БИ-2» в `event_artists` — один артист;
 *   • артист — фасет КАТАЛОГА, как категория и организатор: сеанс не требуется,
 *     события собираются со всех площадок и городов. Набор обязан совпадать с
 *     набором публичного каталога;
 *   • у артиста, в отличие от города, есть СВОИ поля — жанр, биография, фото,
 *     соцсети, — и они попадают в разметку `MusicGroup`;
 *   • `seo_title`/`seo_description` есть ТОЛЬКО у артиста: имя исполнителя —
 *     то, ради чего страницу правят руками. Проверяются обе ветки — ручная и
 *     сгенерированная.
 *
 * АДРЕСА НАПИСАНЫ ЯВНО, а не через `Str::slug()`: адрес — публичный контракт, и
 * его изменение обязано быть видно в диффе теста, а не приходить незаметно из
 * обновления библиотеки транслитерации (`Тюмень` → `tiumen`).
 */
final class LandingArtistTest extends TestCase
{
    use CatalogFixtures;
    use RefreshDatabase;

    // ── Содержимое страницы ────────────────────────────────────────────────

    public function test_an_artist_page_lists_its_events_with_links(): void
    {
        $event = $this->seedCatalogEvent('Рок-концерт', 'zal-art-1', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        $response = $this->get('/artist/bi-2');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('Афиша артиста «Би-2»', false);
        $response->assertSee('Рок-концерт', false);
        $response->assertSee('href="' . $this->eventUrl($event) . '"', false);
    }

    public function test_an_artist_page_gathers_events_of_every_city(): void
    {
        $first = $this->seedCatalogEvent('Концерт в Москве', 'zal-art-2', 'Москва');
        $second = $this->seedCatalogEvent('Концерт в Казани', 'zal-art-3', 'Казань');

        $this->seedArtist('Би-2', 'bi-2', [$first['event'], $second['event']]);

        $response = $this->get('/artist/bi-2');

        $response->assertStatus(200);
        $response->assertSee('href="' . $this->eventUrl($first) . '"', false);
        $response->assertSee('href="' . $this->eventUrl($second) . '"', false);
    }

    public function test_an_artist_page_does_not_show_another_artists_events(): void
    {
        $mine = $this->seedCatalogEvent('Мой концерт', 'zal-art-4', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$mine['event']]);

        $theirs = $this->seedCatalogEvent('Чужой концерт', 'zal-art-5', 'Москва');
        $this->seedArtist('Ночные снайперы', 'nochnye-snaipery', [$theirs['event']]);

        $response = $this->get('/artist/bi-2');

        $response->assertStatus(200);
        $response->assertSee('href="' . $this->eventUrl($mine) . '"', false);
        // ОТРИЦАТЕЛЬНЫЙ СЛУЧАЙ — то, что делает тест осмысленным: проверка
        // только на присутствие своего события прошла бы и на странице, которая
        // показывает вообще всё.
        $response->assertDontSee($this->eventUrl($theirs), false);
        $response->assertDontSee('Чужой концерт', false);
    }

    /**
     * Два написания одного имени в `event_artists` — ОДНА страница.
     *
     * ЭТО ГЛАВНЫЙ ТЕСТ B-1. До появления сущности «Би-2» и «БИ-2» были двумя
     * разными строками внутри событий, и страница, собранная по `event_artists`,
     * была бы двумя «тонкими» страницами — по одной на написание, каждая с
     * половиной событий. `artists` сводит их в одну запись по
     * `normalized_name`, и страница обязана показывать события ОБОИХ написаний.
     */
    public function test_an_artist_page_does_not_split_by_spelling(): void
    {
        $first = $this->seedCatalogEvent('Концерт 1', 'zal-art-6', 'Москва');
        $second = $this->seedCatalogEvent('Концерт 2', 'zal-art-7', 'Москва');

        // Источник — свободный текст, как его заполняет администратор.
        $this->seedEventArtist($first['event'], 'Би-2');
        $this->seedEventArtist($second['event'], 'БИ-2');

        // Каноническая запись одна: ключ слияния у обоих написаний совпадает.
        $this->assertSame(Artist::normalizeName('Би-2'), Artist::normalizeName('БИ-2'));

        $this->seedArtist('Би-2', 'bi-2', [$first['event'], $second['event']]);

        $response = $this->get('/artist/bi-2');

        $response->assertStatus(200);
        $response->assertSee('href="' . $this->eventUrl($first) . '"', false);
        $response->assertSee('href="' . $this->eventUrl($second) . '"', false);
    }

    public function test_an_artist_page_shows_an_event_that_has_no_session(): void
    {
        $event = $this->seedCatalogEvent('Без сеанса', 'zal-art-8', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        // Сеанс удаляем — событие остаётся опубликованным. Публичный каталог
        // фильтруется только по статусу, поэтому событие в нём есть; страница
        // артиста обязана показывать тот же набор. Город и площадка, наоборот,
        // требуют сеанс: там нечего было бы показать.
        DB::table('sessions')->where('id', $event['session'])->delete();
        Cache::flush();

        $this->get('/artist/bi-2')->assertStatus(200)->assertSee('Без сеанса', false);
        $this->get('/city/moskva')->assertStatus(404);
    }

    public function test_an_artist_without_events_answers_404(): void
    {
        // Артист существует, но событий у него нет: страница была бы «тонкой» —
        // заголовок и ноль ссылок. Отсутствие страницы честнее пустой.
        $this->seedArtist('Би-2', 'bi-2');

        $response = $this->get('/artist/bi-2');

        $response->assertStatus(404);
        $response->assertSee('пока нет мероприятий', false);
    }

    public function test_an_artist_whose_only_event_is_a_draft_answers_404(): void
    {
        $event = $this->seedCatalogEvent('Черновик', 'zal-art-9', 'Москва', status: EventStatus::DRAFT);
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        // Порог существования страницы — хотя бы одно ПУБЛИЧНО видимое событие.
        $this->get('/artist/bi-2')->assertStatus(404);
    }

    public function test_an_unknown_artist_slug_answers_a_branded_404(): void
    {
        $response = $this->get('/artist/artista-kotorogo-net');

        $response->assertStatus(404);
        $response->assertSee('Страница не найдена', false);
        $response->assertSee('href="/"', false);
    }

    public function test_an_inactive_artist_has_no_page(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-10', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']], 'inactive');

        // Страница снятого с публикации артиста не должна открываться: иначе в
        // индексе остаётся адрес сущности, которую администратор закрыл.
        $this->get('/artist/bi-2')->assertStatus(404);
    }

    public function test_a_soft_deleted_event_leaves_the_artist_page(): void
    {
        $event = $this->seedCatalogEvent('Удалённый', 'zal-art-11', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        DB::table('events')->where('id', $event['event'])->update(['deleted_at' => now()]);
        Cache::flush();

        $this->get('/artist/bi-2')->assertStatus(404);
    }

    // ── Метатеги ───────────────────────────────────────────────────────────

    public function test_the_artist_page_uses_the_manual_seo_title_when_set(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-12', 'Москва');
        $this->seedArtist(
            'Би-2',
            'bi-2',
            [$event['event']],
            seoTitle: 'Би-2 — билеты на концерты 2026',
            seoDescription: 'Купить билеты на концерты группы Би-2: расписание, залы и цены.',
        );

        $response = $this->get('/artist/bi-2');

        $response->assertStatus(200);
        // Ручное значение уважается — колонки `seo_title`/`seo_description` для
        // этого и существуют: имя исполнителя правит человек.
        $response->assertSee('<title>Би-2 — билеты на концерты 2026</title>', false);
        $response->assertSee(
            'content="Купить билеты на концерты группы Би-2: расписание, залы и цены."',
            false,
        );
    }

    public function test_the_artist_page_falls_back_to_a_generated_title(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-13', 'Москва');
        // Карточка без ручных метатегов — обычное состояние: их проставляют
        // позже, а событие публикуют раньше.
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        $response = $this->get('/artist/bi-2');

        $response->assertStatus(200);
        $response->assertSee(
            '<title>Афиша артиста «Би-2» — билеты и расписание</title>',
            false,
        );
    }

    public function test_the_second_page_does_not_repeat_the_first_pages_title(): void
    {
        // 21 событие — на две страницы по 20 (`CatalogService::PER_PAGE`).
        $ids = [];

        for ($i = 1; $i <= 21; $i++) {
            $ids[] = $this->seedCatalogEvent('Концерт ' . $i, 'zal-page-' . $i, 'Москва')['event'];
        }

        $this->seedArtist('Би-2', 'bi-2', $ids);

        $first = (string) $this->get('/artist/bi-2')->getContent();
        $second = (string) $this->get('/artist/bi-2?page=2')->getContent();

        $this->assertStringContainsString('Афиша артиста «Би-2»', $first);
        // Суффикс страницы добавляется и к СГЕНЕРИРОВАННОМУ заголовку: без него
        // вторая страница получила бы тот же `<title>`, что первая, и они стали
        // бы дублями.
        $this->assertStringContainsString('— страница 2', $second);
        $this->assertNotSame($this->titleOf($first), $this->titleOf($second));
    }

    // ── Разметка ───────────────────────────────────────────────────────────

    public function test_the_artist_page_carries_a_music_group_block(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-14', 'Москва');
        $this->seedArtist(
            'Би-2',
            'bi-2',
            [$event['event']],
            genre: 'Рок',
            bio: 'Белорусская рок-группа, основана в 1988 году.',
            photo: 'artists/bi-2.jpg',
            socialLinks: ['https://vk.com/bi2', 'https://t.me/bi2band'],
        );

        $block = $this->jsonLdOfType((string) $this->get('/artist/bi-2')->getContent(), 'MusicGroup');

        $this->assertNotNull($block, 'на странице артиста нет MusicGroup');
        $this->assertSame('https://schema.org', $block['@context']);
        $this->assertSame('Би-2', $block['name']);
        $this->assertSame(route('landing.artist', ['slug' => 'bi-2']), $block['url']);
        $this->assertSame('Белорусская рок-группа, основана в 1988 году.', $block['description']);
        $this->assertSame('Рок', $block['genre']);
        // Фотография проходит через `AssetUrl`: в проекте два разных корня
        // (`/storage/...` и `/`), и ручная склейка уже однажды дала `og:image` со
        // ссылкой в 404. Хост в ассерте не фиксируется — он свой на каждом стенде.
        $this->assertStringContainsString('/storage/artists/bi-2.jpg', $block['image']);
        $this->assertSame(['https://vk.com/bi2', 'https://t.me/bi2band'], $block['sameAs']);
        $this->assertNoEmptyScalars($block);
    }

    public function test_the_music_group_block_accepts_social_links_as_a_map(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-15', 'Москва');
        // Вторая форма колонки: карта «сеть → адрес». Обе формы означают одно и
        // то же и обязаны дать непустой `sameAs` — иначе разметка молча теряла
        // бы ссылки в зависимости от того, как их ввели.
        $this->seedArtist(
            'Би-2',
            'bi-2',
            [$event['event']],
            socialLinks: ['vk' => 'https://vk.com/bi2', 'site' => 'https://bi-2.com'],
        );

        $block = $this->jsonLdOfType((string) $this->get('/artist/bi-2')->getContent(), 'MusicGroup');

        $this->assertNotNull($block);
        $this->assertSame(['https://vk.com/bi2', 'https://bi-2.com'], $block['sameAs']);
    }

    public function test_the_music_group_block_omits_absent_fields(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-16', 'Москва');
        // Жанр, биография, фото и соцсети не заполнены — обычное состояние.
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        $block = $this->jsonLdOfType((string) $this->get('/artist/bi-2')->getContent(), 'MusicGroup');

        $this->assertNotNull($block, 'на странице артиста нет MusicGroup');

        // «Нет данных» обязано выглядеть как ОТСУТСТВИЕ КЛЮЧА, а не как пустая
        // строка: валидатор Google считает `""` значением и ругается на неполное
        // поле, тогда как отсутствие ключа ошибкой не является.
        foreach (['description', 'image', 'genre', 'sameAs'] as $absent) {
            $this->assertArrayNotHasKey($absent, $block, sprintf('пустое поле %s осталось в разметке', $absent));
        }

        $this->assertNoEmptyScalars($block);
    }

    public function test_the_artist_breadcrumb_is_two_levels_deep(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-17', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        $html = (string) $this->get('/artist/bi-2')->getContent();
        $elements = $this->breadcrumbList($html)['itemListElement'];

        $this->assertSame(['Главная', 'Афиша артиста «Би-2»'], array_column($elements, 'name'));
        // Требование Google: последняя позиция обязана совпадать с canonical,
        // иначе разметка отбрасывается целиком.
        $this->assertSame(
            $this->canonicalOf($html),
            $elements[count($elements) - 1]['item'],
            'последняя крошка не совпадает с canonical',
        );
        $this->assertSame(range(1, count($elements)), array_column($elements, 'position'));
    }

    public function test_the_artist_page_carries_an_item_list_of_its_events(): void
    {
        $event = $this->seedCatalogEvent('Симфония', 'zal-art-18', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        $itemList = $this->jsonLdOfType((string) $this->get('/artist/bi-2')->getContent(), 'ItemList');

        $this->assertNotNull($itemList, 'на странице нет ItemList');
        $this->assertSame($this->eventUrl($event), $itemList['itemListElement'][0]['url']);
        $this->assertSame(1, $itemList['itemListElement'][0]['position']);
    }

    public function test_the_artist_page_links_to_neighbouring_artists(): void
    {
        $mine = $this->seedCatalogEvent('Концерт', 'zal-rel-a1', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$mine['event']]);

        $other = $this->seedCatalogEvent('Опера', 'zal-rel-a2', 'Казань');
        $this->seedArtist('Ночные снайперы', 'nochnye-snaipery', [$other['event']]);

        // Третий артист существует, но публичных событий у него нет: его
        // страница отвечает 404, поэтому соседом он быть не может.
        $empty = $this->seedCatalogEvent('Черновик', 'zal-rel-a3', 'Казань', status: EventStatus::DRAFT);
        $this->seedArtist('Пустой артист', 'pustoy-artist', [$empty['event']]);

        $html = (string) $this->get('/artist/bi-2')->getContent();

        // ПОЛОЖИТЕЛЬНЫЙ КОНТРОЛЬ: без него весь тест был бы зелёным и на
        // странице, где блока ссылок нет вовсе, — то есть проверял бы
        // отсутствие, ничего не проверив.
        $this->assertStringContainsString(route('landing.artist', ['slug' => 'nochnye-snaipery']), $html);
        // ОТРИЦАТЕЛЬНЫЙ: ссылка на 404 в перелинковке — та же ошибка, что адрес
        // в карте сайта, ведущий на 404.
        $this->assertStringNotContainsString(route('landing.artist', ['slug' => 'pustoy-artist']), $html);
        // Сам на себя артист не ссылается — проверяется ВНУТРИ блока
        // перелинковки, а не по всей странице: последняя крошка навигации ведёт
        // на canonical страницы, то есть на неё же, и это правильно.
        $this->assertStringNotContainsString(
            route('landing.artist', ['slug' => 'bi-2']),
            $this->relatedBlock($html),
        );
    }

    public function test_an_artist_page_is_indexable(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-19', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        // `PreventIndexingOfServicePages` живёт в GLOBAL stack и закрывает
        // служебные пути заголовком `X-Robots-Tag: noindex, noarchive`; список
        // путей перечислен явно и пополняется вручную. Если `/artist/*` туда
        // однажды попадёт (или список перевернут в «закрыть всё, кроме»),
        // страница будет отвечать 200 и присутствовать в карте, но в индексе её
        // не будет — и заметить это можно только такой проверкой.
        $response = $this->get('/artist/bi-2');

        $response->assertStatus(200);
        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_the_artist_page_does_not_depend_on_the_built_storefront(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-art-20', 'Москва');
        $this->seedArtist('Би-2', 'bi-2', [$event['event']]);

        config(['nabilet.storefront.shell_path' => base_path('dist/definitely-missing.html')]);

        $response = $this->get('/artist/bi-2');

        $response->assertStatus(200);
        $response->assertSee('Концерт', false);
        $response->assertDontSee('<script src=', false);
        $response->assertDontSee('<link rel="stylesheet"', false);
    }

    // ── Вспомогательное ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function breadcrumbList(string $html): array
    {
        $list = $this->jsonLdOfType($html, 'BreadcrumbList');

        $this->assertNotNull($list, 'на странице нет BreadcrumbList');

        return $list;
    }

    private function canonicalOf(string $html): string
    {
        $found = preg_match('#<link rel="canonical" href="([^"]*)"#', $html, $matches);

        $this->assertSame(1, $found, 'на странице нет canonical');

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function titleOf(string $html): string
    {
        $found = preg_match('#<title>(.*?)</title>#s', $html, $matches);

        $this->assertSame(1, $found, 'на странице нет title');

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    /**
     * Только блок перелинковки «Смотрите также».
     *
     * Нужен, потому что ссылка на саму страницу ЕСТЬ в навигации — последняя
     * крошка ведёт на canonical, — и проверка «артист не ссылается на себя» по
     * всей странице была бы заведомо ложной. Проверять отсутствие ссылки нужно
     * там, где она не должна появляться.
     */
    private function relatedBlock(string $html): string
    {
        $found = preg_match('#<ul class="related">(.*?)</ul>#s', $html, $matches);

        $this->assertSame(1, $found, 'на странице нет блока перелинковки');

        return $matches[1];
    }

    /**
     * Разбор блоков JSON-LD поиском по `<script type="application/ld+json">`.
     *
     * Регулярным выражением, а не `DOMDocument`/`SimpleXML`: тест не должен
     * зависеть от наличия расширения — иначе «зелёный» результат определялся бы
     * сборкой PHP, а не разметкой страницы.
     *
     * @return array<string, mixed>|null
     */
    private function jsonLdOfType(string $html, string $type): ?array
    {
        preg_match_all(
            '#<script type="application/ld\+json">(.*?)</script>#s',
            $html,
            $matches,
        );

        foreach ($matches[1] as $raw) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded) && ($decoded['@type'] ?? null) === $type) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Ни одного пустого значения в разметке — рекурсивно.
     *
     * ЭТО ПРОВЕРКА РЕГРЕССИИ НА `pruneEmpty`, а не украшение (критерий 20
     * SEO-аудита). Валидатор Google считает пустую строку ЗНАЧЕНИЕМ и ругается
     * на неполное поле: `"genre": ""` — ошибка, тогда как отсутствие ключа
     * ошибкой не является. Поэтому «нет данных» обязано выглядеть как отсутствие
     * ключа, и единственный способ это закрепить — пройти по готовому JSON-LD и
     * убедиться, что пустых скаляров в нём не осталось.
     *
     * `0` и `false` пропускаются намеренно: в schema.org это осмысленные
     * значения (`price: 0` у бесплатного события), и именно на них ошибается
     * `array_filter()` без лямбды — тот самый, который тут нельзя использовать.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoEmptyScalars(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $where = $path === '' ? (string) $key : $path . '.' . $key;

            if (is_array($value)) {
                $this->assertNoEmptyScalars($value, $where);

                continue;
            }

            if ($value === null || $value === '') {
                $this->fail(sprintf('в JSON-LD осталось пустое значение: %s', $where));
            }
        }
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
