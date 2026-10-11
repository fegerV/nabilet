<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Modules\Seo\Services\CatalogService;
use Nabilet\Tests\Support\CatalogFixtures;
use Tests\TestCase;

/**
 * Посадочные страницы города и площадки (SEO-аудит P-12).
 *
 * ДЕФЕКТ. Длинный хвост запросов — «афиша Сургута», «что идёт в филармонии»,
 * «концерт в Кремлёвском дворце» — не имел адресуемых страниц: каталог это
 * hash-SPA с одним индексируемым URL (`/`), а фильтры живут в параметрах запроса
 * и роботом не обходятся. У сайта физически не было адреса, который можно
 * показать в выдаче под такой запрос, — то есть потолок органического трафика
 * был задан структурой сайта, а не конкуренцией.
 *
 * ЧТО ИМЕННО ПРОВЕРЯЕТСЯ ЗДЕСЬ. Не «страница отвечает 200», а свойства, ради
 * которых она делалась:
 *
 *   • содержимое приходит СЕРВЕРОМ, без JavaScript — иначе робот не увидит
 *     списка, и страница не отличается от пустой оболочки;
 *   • страница НЕ ЗАВИСИТ от сборки витрины: переход из выдачи не должен
 *     упираться в «витрина не собрана»;
 *   • пустая коллекция — 404, а не документ из заголовка и нуля ссылок
 *     («тонкая страница» понижает весь раздел, а не только себя);
 *   • последняя позиция `BreadcrumbList` совпадает с canonical — требование
 *     Google, при нарушении разметка отбрасывается целиком;
 *   • непубличное и удалённое в список не попадает.
 *
 * ГДЕ НУЖНО НЕСКОЛЬКО СОБЫТИЙ НА ОДНОЙ СТРАНИЦЕ, ИСПОЛЬЗУЕТСЯ ГОРОД, А НЕ
 * ПЛОЩАДКА. Фикстура создаёт на каждое событие свою площадку, поэтому две
 * площадки с одним адресом в базе не создать: `venues.slug` уникален в пределах
 * организации (`uq_venues_org_slug`), а организация у фикстуры на каждое событие
 * своя. Город же собирает события разных площадок, поэтому все сценарии «список
 * из N событий», включая пагинацию и порядок, идут через `/city/…`.
 *
 * Глобальная уникальность slug'а площадки — свойство ТЕКУЩЕГО развёртывания, а не
 * схемы (аудит P-03): пока клиент один, `venueBySlug()` однозначен. Тест ниже
 * закрепляет адрес площадки как публичный контракт, но не уникальность — её
 * обеспечить сможет только организация в адресе.
 */
final class LandingPageTest extends TestCase
{
    use CatalogFixtures;
    use RefreshDatabase;

    // ── Площадка ───────────────────────────────────────────────────────────

    public function test_a_venue_page_lists_its_events_with_links(): void
    {
        $event = $this->seedCatalogEvent('Новогодний концерт', 'kremlevskiy-dvorec', 'Москва');

        $response = $this->get('/venue/kremlevskiy-dvorec');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('Афиша площадки «Большой театр»', false);
        $response->assertSee('Новогодний концерт', false);
        // Ссылка на страницу события — то, ради чего список существует: без неё
        // страница площадки никуда не ведёт, и робот не находит сами события.
        $response->assertSee('href="' . $this->eventUrl($event) . '"', false);
    }

    public function test_a_venue_page_shows_the_price_as_the_storefront_does(): void
    {
        $this->seedCatalogEvent('Балет', 'teatr-opery', 'Москва', priceAmount: 250_000);

        // 250 000 копеек — это 2 500 ₽. Целые рубли печатаются без копеек, как в
        // `resources/js/lib/format.ts`; «2 500,00 ₽» на посадочной рядом с
        // «2 500 ₽» на витрине читались бы как две разные цены.
        $this->get('/venue/teatr-opery')->assertSee('от 2 500 ₽', false);
    }

    public function test_a_venue_without_events_is_not_indexable_as_an_empty_page(): void
    {
        // Площадка есть, событий у неё нет: единственный способ её создать —
        // через фикстуру и затем снять событие с публикации.
        $this->seedCatalogEvent('Черновик', 'pustoy-zal', 'Москва', status: EventStatus::DRAFT);

        $response = $this->get('/venue/pustoy-zal');

        $response->assertStatus(404);
        $response->assertSee('пока нет мероприятий', false);
    }

    public function test_an_unknown_venue_slug_answers_a_branded_404(): void
    {
        $response = $this->get('/venue/nesushchestvuyushchiy-zal');

        $response->assertStatus(404);
        $response->assertSee('Страница не найдена', false);
        $response->assertSee('href="/"', false);
    }

    public function test_an_inactive_venue_has_no_page(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zakrytyy-zal', 'Москва');

        DB::table('venues')->where('id', $event['venue'])->update(['status' => 'inactive']);
        Cache::flush();

        // Страница снятой с публикации площадки не должна открываться: иначе в
        // индексе остаётся адрес сущности, которую администратор закрыл.
        $this->get('/venue/zakrytyy-zal')->assertStatus(404);
    }

    // ── Разметка места (аудит P-12) ────────────────────────────────────────

    public function test_a_venue_page_carries_a_place_block(): void
    {
        $event = $this->seedCatalogEvent('Опера', 'kreml-place', 'Москва', venueName: 'Кремлёвский дворец');

        DB::table('venues')->where('id', $event['venue'])->update([
            'address' => 'ул. Воздвиженка, 1',
            'country' => 'RU',
        ]);
        Cache::flush();

        $place = $this->jsonLdOfType((string) $this->get('/venue/kreml-place')->getContent(), 'Place');

        $this->assertNotNull($place, 'на странице площадки нет Place');
        $this->assertSame('https://schema.org', $place['@context']);
        $this->assertSame('Кремлёвский дворец', $place['name']);
        // `url` — адрес КОЛЛЕКЦИИ без `?page`: разметка описывает сущность, а не
        // её вторую страницу.
        $this->assertSame(route('landing.venue', ['slug' => 'kreml-place']), $place['url']);
        $this->assertSame('PostalAddress', $place['address']['@type']);
        $this->assertSame('ул. Воздвиженка, 1', $place['address']['streetAddress']);
        $this->assertSame('Москва', $place['address']['addressLocality']);
        $this->assertSame('RU', $place['address']['addressCountry']);
    }

    public function test_the_place_block_keeps_a_minimal_address_when_the_venue_has_none(): void
    {
        // Площадка без города и адреса — обычное состояние: администратор заводит
        // зал раньше, чем узнаёт адрес. `venues.address` и `venues.city` nullable.
        $this->seedCatalogEvent('Опера', 'zal-bez-adresa', null);

        $place = $this->jsonLdOfType((string) $this->get('/venue/zal-bez-adresa')->getContent(), 'Place');

        $this->assertNotNull($place, 'на странице площадки нет Place');
        // Пустая строка в `streetAddress` — для валидатора Google ошибка
        // неполного поля, тогда как отсутствие ключа ошибкой не является.
        $this->assertArrayNotHasKey('streetAddress', $place['address']);
        $this->assertArrayNotHasKey('addressLocality', $place['address']);
        // Страна остаётся единственным полем адреса, и это осознанно: без неё
        // `address` выродился бы в `{"@type": "PostalAddress"}` — адрес, у
        // которого нет ни одного поля, и `pruneEmpty` такой объект НЕ убирает,
        // потому что он непуст. Значение совпадает с тем, что отдаёт разметка
        // события для того же места.
        $this->assertSame('RU', $place['address']['addressCountry']);
        $this->assertNoEmptyScalars($place);
    }

    // ── Город ──────────────────────────────────────────────────────────────

    public function test_a_city_page_lists_events_of_every_venue_in_it(): void
    {
        $first = $this->seedCatalogEvent('Опера', 'teatr-1', 'Казань', venueName: 'Театр оперы');
        $second = $this->seedCatalogEvent('Балет', 'teatr-2', 'Казань', venueName: 'Театр балета');

        $response = $this->get('/city/kazan');

        $response->assertStatus(200);
        $response->assertSee('Афиша города Казань', false);
        $response->assertSee('href="' . $this->eventUrl($first) . '"', false);
        $response->assertSee('href="' . $this->eventUrl($second) . '"', false);
        // На странице города площадка указывается рядом с датой — иначе две
        // строки одного события в разных залах неразличимы.
        $response->assertSee('Театр оперы', false);
    }

    public function test_a_city_slug_accepts_every_spelling_of_the_name(): void
    {
        // Города как сущности в схеме нет: `venues.city` — свободный текст.
        // Два написания одного города обязаны открывать одну страницу, иначе
        // половина площадок выпадает из города, а в индексе появляются две
        // страницы-полудубля.
        $first = $this->seedCatalogEvent('Джаз', 'dzhaz-klub', 'Санкт-Петербург');
        $second = $this->seedCatalogEvent('Рок', 'rok-klub', 'Санкт Петербург');

        $response = $this->get('/city/sankt-peterburg');

        $response->assertStatus(200);
        $response->assertSee('href="' . $this->eventUrl($first) . '"', false);
        $response->assertSee('href="' . $this->eventUrl($second) . '"', false);
    }

    public function test_a_city_without_events_answers_404(): void
    {
        $this->seedCatalogEvent('Черновик', 'zal', 'Тюмень', status: EventStatus::DRAFT);

        // Адрес написан ЯВНО, а не через `Str::slug()`: адрес города — это
        // публичный контракт, который уже лежит в индексе и во внешних ссылках.
        // Транслитерация (`Тюмень` → `tiumen`, а не `tyumen`) приходит из
        // `voku/portable-ascii`, и её обновление сменило бы адреса у всех
        // городов сразу. Если этот тест однажды упадёт, это не «устаревший
        // тест», а сигнал, что публичные адреса поехали.
        $response = $this->get('/city/tiumen');

        $response->assertStatus(404);
        $response->assertSee('пока нет мероприятий', false);
    }

    public function test_an_unknown_city_answers_404(): void
    {
        $this->get('/city/gorod-kotorogo-net')->assertStatus(404);
    }

    // ── Что в список НЕ попадает ───────────────────────────────────────────

    /**
     * Публично видимые статусы и только они.
     *
     * Отдельный тест на каждый статус, а не цикл: цикл с `seedCatalogEvent()`
     * внутри создавал бы по организации на итерацию, и падение одной итерации
     * маскировало бы состояние остальных — ровно так уже ловили дефект P-03.
     */
    public function test_a_draft_event_is_hidden(): void
    {
        $this->seedCatalogEvent('Черновик', 'zal-a', 'Пермь', status: EventStatus::DRAFT);

        $this->get('/venue/zal-a')->assertStatus(404);
    }

    public function test_a_cancelled_event_is_hidden(): void
    {
        $this->seedCatalogEvent('Отменённый', 'zal-b', 'Пермь', status: EventStatus::CANCELLED);

        $this->get('/venue/zal-b')->assertStatus(404);
    }

    public function test_a_scheduled_event_is_hidden(): void
    {
        $this->seedCatalogEvent('Отложенный', 'zal-c', 'Пермь', status: EventStatus::SCHEDULED);

        $this->get('/venue/zal-c')->assertStatus(404);
    }

    public function test_a_soft_deleted_event_leaves_the_landing_page(): void
    {
        $event = $this->seedCatalogEvent('Удалённый', 'zal-d', 'Пермь');

        // Мягкое удаление (аудит P-24): строка остаётся в таблице, поэтому
        // фильтр `deleted_at` обязателен — иначе удалённое мероприятие
        // продолжало бы наполнять город и площадку.
        DB::table('events')->where('id', $event['event'])->update(['deleted_at' => now()]);
        Cache::flush();

        $this->get('/venue/zal-d')->assertStatus(404);
    }

    public function test_a_completed_event_stays_on_the_page(): void
    {
        // `completed` входит в `publiclyVisible()`: страница прошедшего концерта
        // это архив с накопленным трафиком, и он обязан остаться доступным.
        $this->seedCatalogEvent(
            'Прошедший концерт',
            'zal-e',
            'Пермь',
            startsAt: now()->subMonth()->toDateTimeString(),
            status: EventStatus::COMPLETED,
        );

        $this->get('/venue/zal-e')->assertStatus(200);
        $this->get('/city/perm')->assertStatus(200)->assertSee('Прошедший концерт', false);
    }

    // ── Разметка ───────────────────────────────────────────────────────────

    public function test_the_breadcrumb_ends_at_the_canonical_address(): void
    {
        $this->seedCatalogEvent('Концерт', 'zal-f', 'Уфа');

        $html = (string) $this->get('/venue/zal-f')->getContent();

        $breadcrumb = $this->breadcrumbList($html);
        $elements = $breadcrumb['itemListElement'];
        $canonical = $this->canonicalOf($html);

        $this->assertSame(route('landing.venue', ['slug' => 'zal-f']), $canonical);

        // Требование Google: последняя позиция `BreadcrumbList` обязана
        // совпадать с canonical. При расхождении разметка отбрасывается целиком,
        // а не «частично применяется».
        $this->assertSame($canonical, $elements[count($elements) - 1]['item'], 'последняя крошка не совпадает с canonical');
        $this->assertSame('Главная', $elements[0]['name']);
        $this->assertSame(
            range(1, count($elements)),
            array_column($elements, 'position'),
        );
    }

    public function test_the_page_carries_an_item_list_of_its_events(): void
    {
        $event = $this->seedCatalogEvent('Симфония', 'zal-g', 'Омск');

        $html = (string) $this->get('/venue/zal-g')->getContent();

        $itemList = $this->jsonLdOfType($html, 'ItemList');

        $this->assertNotNull($itemList, 'на странице нет ItemList');
        $this->assertSame($this->eventUrl($event), $itemList['itemListElement'][0]['url']);
        $this->assertSame('Симфония', $itemList['itemListElement'][0]['name']);
        $this->assertSame(1, $itemList['itemListElement'][0]['position']);
    }

    public function test_the_page_carries_a_unique_title_and_description(): void
    {
        $this->seedCatalogEvent('Концерт', 'zal-h', 'Сочи');

        $response = $this->get('/venue/zal-h');

        $response->assertSee('<title>Афиша площадки «Большой театр» — билеты и расписание</title>', false);
        $response->assertSee('name="description"', false);
    }

    public function test_the_page_links_to_neighbouring_collections(): void
    {
        $this->seedCatalogEvent('Опера', 'zal-rel-1', 'Самара');
        $this->seedCatalogEvent('Балет', 'zal-rel-2', 'Самара');

        $html = (string) $this->get('/venue/zal-rel-1')->getContent();

        // Без исходящих ссылок посадочная страница — тупик: робот, попавший на
        // неё из выдачи, не находит соседние площадки.
        $this->assertStringContainsString(route('landing.venue', ['slug' => 'zal-rel-2']), $html);
    }

    // ── Пагинация ──────────────────────────────────────────────────────────

    public function test_pagination_splits_the_collection_and_keeps_canonical_self(): void
    {
        for ($i = 1; $i <= CatalogService::PER_PAGE + 1; $i++) {
            $this->seedCatalogEvent(
                sprintf('Событие %d', $i),
                'zal-pag-' . $i,
                'Тверь',
                startsAt: now()->addDays($i)->toDateTimeString(),
            );
        }

        $first = $this->get('/city/tver');
        $first->assertStatus(200);
        $first->assertSee('Страница 1 из 2', false);

        $second = $this->get('/city/tver?page=2');
        $second->assertStatus(200);
        // Заголовок страницы пагинации обязан отличаться от первой: иначе две
        // страницы одного шаблона становятся дублями друг друга.
        $second->assertSee('— страница 2', false);

        $this->assertSame(
            route('landing.city', ['slug' => 'tver']) . '?page=2',
            $this->canonicalOf((string) $second->getContent()),
        );
    }

    public function test_the_page_number_is_read_from_the_query_string(): void
    {
        for ($i = 1; $i <= CatalogService::PER_PAGE + 1; $i++) {
            $this->seedCatalogEvent(
                sprintf('Событие %d', $i),
                'zal-q-' . $i,
                'Брянск',
                startsAt: now()->addDays($i)->toDateTimeString(),
            );
        }

        // Если бы номер страницы читался из параметра маршрута, `?page=2`
        // до действия не доехал бы и оба адреса вернули бы первую страницу —
        // тест фиксирует именно это.
        $this->assertStringContainsString(
            'Страница 2 из 2',
            (string) $this->get('/city/briansk?page=2')->getContent(),
        );
    }

    public function test_a_page_beyond_the_last_one_answers_404(): void
    {
        $this->seedCatalogEvent('Одно событие', 'zal-one', 'Курск');

        // `?page=99` — не «пустая страница», а несуществующая. Иначе перебор
        // номеров роботом создавал бы неограниченное число «тонких» адресов под
        // живым кодом ответа.
        $this->get('/venue/zal-one?page=99')->assertStatus(404);
        $this->get('/city/kursk?page=99')->assertStatus(404);
    }

    // ── Независимость от сборки и от API ───────────────────────────────────

    public function test_the_landing_page_does_not_depend_on_the_built_storefront(): void
    {
        $this->seedCatalogEvent('Концерт', 'zal-i', 'Вологда');

        // Сборки нет — и это ровно то состояние, в котором страница обязана
        // работать: переход из поисковой выдачи не должен упираться в
        // «витрина не собрана». Стили и разметка встроены в документ.
        config(['nabilet.storefront.shell_path' => base_path('dist/definitely-missing.html')]);

        $response = $this->get('/venue/zal-i');

        $response->assertStatus(200);
        $response->assertSee('Концерт', false);
        // Ни одного внешнего ресурса: только встроенные стили.
        $response->assertDontSee('<script src=', false);
        $response->assertDontSee('<link rel="stylesheet"', false);
    }

    public function test_landing_routes_do_not_shadow_the_api(): void
    {
        // Посадочные страницы живут в web-группе, а `Route::fallback()` там же.
        // Если бы catch-all перехватывал `/api/v1/...`, весь API начал бы
        // отвечать HTML-страницей 404.
        $this->get('/api/v1/events')
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/json');
    }

    /**
     * Посадочная страница обязана быть ИНДЕКСИРУЕМОЙ.
     *
     * `PreventIndexingOfServicePages` живёт в GLOBAL stack и закрывает
     * служебные пути заголовком `X-Robots-Tag: noindex, noarchive`. Список
     * путей там перечислен явно, и его собственный докблок предупреждает, что
     * «закрыть всё, кроме /event/*» было бы опасно — новая полезная страница
     * молча оказалась бы неиндексируемой. `/city/*` и `/venue/*` — ровно такой
     * случай, и провал здесь не виден ни по коду ответа, ни по карте сайта:
     * страница отвечает 200, ссылки есть, а в индексе её нет.
     *
     * Проверяются оба входа сразу, потому что правило одно, а путей два.
     */
    public function test_a_landing_page_is_indexable(): void
    {
        $this->seedCatalogEvent('Концерт', 'zal-idx', 'Смоленск');

        foreach (['/venue/zal-idx', '/city/smolensk'] as $path) {
            $response = $this->get($path);

            $response->assertStatus(200);
            $response->assertHeaderMissing('X-Robots-Tag');
        }
    }

    // ── Порядок ────────────────────────────────────────────────────────────

    public function test_upcoming_events_come_before_past_ones(): void
    {
        $this->seedCatalogEvent(
            'Прошлогодний концерт',
            'zal-j-past',
            'Тула',
            startsAt: now()->subYear()->toDateTimeString(),
        );

        $this->seedCatalogEvent(
            'Ближайший концерт',
            'zal-j-soon',
            'Тула',
            startsAt: now()->addWeek()->toDateTimeString(),
        );

        $html = (string) $this->get('/city/tula')->getContent();

        // Один город, два события: ближайшее обязано быть выше прошедшего.
        // Сортировка задана в SQL, потому что список страничный — сортировка в
        // PHP упорядочила бы только текущую двадцатку, а не всю выборку.
        $this->assertLessThan(
            strpos($html, 'Прошлогодний концерт'),
            strpos($html, 'Ближайший концерт'),
            'прошедшее событие оказалось выше предстоящего',
        );
    }

    public function test_a_landing_page_shows_events_whose_publication_date_is_missing(): void
    {
        $event = $this->seedCatalogEvent('Без даты публикации', 'zal-k', 'Ижевск');

        // Посадочная страница мягче карты сайта: она навигационная, и событие,
        // у которого администратор не проставил дату публикации, обязано
        // остаться видимым — исчезновение по невидимой в админке причине
        // выглядит как пропавшее мероприятие.
        DB::table('events')->where('id', $event['event'])->update(['published_at' => null]);
        Cache::flush();

        $this->get('/venue/zal-k')->assertStatus(200)->assertSee('Без даты публикации', false);
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
     * на неполное поле: `"streetAddress": ""` — ошибка, тогда как отсутствие
     * ключа ошибкой не является. Поэтому «нет данных» обязано выглядеть как
     * отсутствие ключа, и единственный способ это закрепить — пройти по готовому
     * JSON-LD и убедиться, что пустых скаляров в нём не осталось.
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
