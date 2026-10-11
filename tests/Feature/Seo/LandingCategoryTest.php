<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Tests\Support\CatalogFixtures;
use Tests\TestCase;

/**
 * Посадочные страницы категории и фасета «город × категория» (SEO-аудит P-12).
 *
 * ПОЧЕМУ ЭТО ОТДЕЛЬНЫЙ ФАЙЛ, А НЕ ДОПОЛНЕНИЕ К `LandingPageTest`. У категории
 * другой предмет: страница города и площадки отвечает на вопрос «что здесь
 * идёт», а категория — фасет КАТАЛОГА. Отсюда три отличия, каждое из которых
 * здесь закреплено:
 *
 *   • категория НЕ требует сеанса (в отличие от города и площадки), потому что
 *     набор её событий обязан совпадать с набором публичного каталога;
 *   • фасет «город × категория» сужает выборку ПО ДВУМ осям сразу, и проверять
 *     его надо событием, которое проходит по одной оси и не проходит по другой;
 *   • у фасета ТРЁХУРОВНЕВАЯ иерархия, поэтому средний уровень крошки обязан
 *     быть реальной страницей города — `item` крошки Google обходит, и битая
 *     ссылка в разметке помечается как «URL not found».
 *
 * АДРЕСА НАПИСАНЫ ЯВНО, а не через `Str::slug()`: адрес — публичный контракт,
 * и его изменение обязано быть видно в диффе теста, а не приходить незаметно из
 * обновления библиотеки транслитерации.
 *
 * КАТЕГОРИИ В РАБОЧЕЙ БАЗЕ ПОКА НЕТ НИ ОДНОЙ (и CRUD-поверхности для них тоже
 * нет), но страницы существуют и обязаны быть проверены сейчас: иначе первый же
 * заведённый раздел каталога попадёт в индекс непроверенным кодом.
 */
final class LandingCategoryTest extends TestCase
{
    use CatalogFixtures;
    use RefreshDatabase;

    // ── Страница категории ─────────────────────────────────────────────────

    public function test_a_category_page_lists_its_events_with_links(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $event = $this->seedCatalogEvent('Рок-концерт', 'zal-cat-1', 'Москва', categoryId: $category);

        $response = $this->get('/category/koncerty');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('Афиша категории «Концерты»', false);
        $response->assertSee('Рок-концерт', false);
        $response->assertSee('href="' . $this->eventUrl($event) . '"', false);
    }

    public function test_a_category_page_gathers_events_of_every_city(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');

        $first = $this->seedCatalogEvent('Концерт в Москве', 'zal-cat-2', 'Москва', categoryId: $category);
        $second = $this->seedCatalogEvent('Концерт в Казани', 'zal-cat-3', 'Казань', categoryId: $category);

        // Категория — фасет КАТАЛОГА, а не места: её страница обязана собирать
        // события всех городов. Если бы она была ограничена одним городом, у неё
        // не было бы собственного смысла — это была бы страница города.
        $response = $this->get('/category/koncerty');

        $response->assertStatus(200);
        $response->assertSee('href="' . $this->eventUrl($first) . '"', false);
        $response->assertSee('href="' . $this->eventUrl($second) . '"', false);
    }

    public function test_a_category_page_shows_an_event_that_has_no_session(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $event = $this->seedCatalogEvent('Без сеанса', 'zal-cat-4', 'Москва', categoryId: $category);

        // Сеанс удаляем — событие остаётся опубликованным. Публичный каталог
        // (`GET /api/v1/events`) фильтруется только по статусу, поэтому событие
        // в нём есть; страница категории обязана показывать тот же набор.
        // Город и площадка, наоборот, требуют сеанс: там нечего было бы показать.
        DB::table('sessions')->where('id', $event['session'])->delete();
        Cache::flush();

        $this->get('/category/koncerty')->assertStatus(200)->assertSee('Без сеанса', false);
        $this->get('/city/moskva')->assertStatus(404);
    }

    public function test_a_category_without_events_answers_404(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');

        // Категория есть, но её единственное событие — черновик.
        $this->seedCatalogEvent('Черновик', 'zal-cat-5', 'Москва', status: EventStatus::DRAFT, categoryId: $category);

        $response = $this->get('/category/koncerty');

        $response->assertStatus(404);
        $response->assertSee('пока нет мероприятий', false);
    }

    public function test_a_category_with_no_events_at_all_answers_404(): void
    {
        $this->seedCategory('Пустая категория', 'pustaya');

        // Категория существует, но событий в ней нет ни одного. 404, а не
        // «тонкая страница» из заголовка и нуля ссылок.
        $this->get('/category/pustaya')->assertStatus(404);
    }

    public function test_an_unknown_category_slug_answers_a_branded_404(): void
    {
        $response = $this->get('/category/kategorii-kotoroy-net');

        $response->assertStatus(404);
        $response->assertSee('Страница не найдена', false);
        $response->assertSee('href="/"', false);
    }

    public function test_an_inactive_category_has_no_page(): void
    {
        $category = $this->seedCategory('Закрытая', 'zakrytaya', 'inactive');
        $this->seedCatalogEvent('Концерт', 'zal-cat-6', 'Москва', categoryId: $category);

        // Страница снятой с публикации категории не должна открываться: иначе в
        // индексе остаётся адрес сущности, которую администратор закрыл.
        $this->get('/category/zakrytaya')->assertStatus(404);
    }

    public function test_a_soft_deleted_event_leaves_the_category_page(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $event = $this->seedCatalogEvent('Удалённый', 'zal-cat-7', 'Москва', categoryId: $category);

        // Мягкое удаление: строка остаётся в таблице, поэтому фильтр `deleted_at`
        // обязателен — иначе удалённое мероприятие продолжало бы наполнять
        // категорию.
        DB::table('events')->where('id', $event['event'])->update(['deleted_at' => now()]);
        Cache::flush();

        $this->get('/category/koncerty')->assertStatus(404);
    }

    // ── Фасет «город × категория» ──────────────────────────────────────────

    public function test_the_city_category_page_narrows_by_both_axes(): void
    {
        $concerts = $this->seedCategory('Концерты', 'koncerty');
        $theatre = $this->seedCategory('Театр', 'teatr');

        $wanted = $this->seedCatalogEvent('Концерт в Москве', 'zal-fc-1', 'Москва', categoryId: $concerts);
        // Проходит по городу, не проходит по категории.
        $otherCategory = $this->seedCatalogEvent('Спектакль в Москве', 'zal-fc-2', 'Москва', categoryId: $theatre);
        // Проходит по категории, не проходит по городу.
        $otherCity = $this->seedCatalogEvent('Концерт в Казани', 'zal-fc-3', 'Казань', categoryId: $concerts);

        $response = $this->get('/city/moskva/koncerty');

        $response->assertStatus(200);
        $response->assertSee('href="' . $this->eventUrl($wanted) . '"', false);
        // Именно эти два «отрицательных» случая и делают тест осмысленным:
        // проверка только на присутствие нужного события прошла бы и на
        // странице, которая не фильтрует вовсе.
        $response->assertDontSee($this->eventUrl($otherCategory), false);
        $response->assertDontSee($this->eventUrl($otherCity), false);
    }

    public function test_the_city_category_page_answers_404_when_the_category_has_nothing_in_that_city(): void
    {
        $concerts = $this->seedCategory('Концерты', 'koncerty');
        $theatre = $this->seedCategory('Театр', 'teatr');

        // Москва существует — в ней есть театр. Концерты есть, но только в
        // Казани. Без первого события тест падал бы на «город не найден», то
        // есть проверял бы не то: сообщение в ассерте ниже отличает один 404
        // от другого, и именно поэтому оно там и написано.
        $this->seedCatalogEvent('Спектакль в Москве', 'zal-fc-4a', 'Москва', categoryId: $theatre);
        $this->seedCatalogEvent('Концерт в Казани', 'zal-fc-4', 'Казань', categoryId: $concerts);

        // Город есть, категория есть, а пересечения нет: адрес не существует.
        // Редирект на город выглядел бы полезнее, но он превратил бы каждый
        // перебор «город × категория» в 200, то есть в неограниченное число
        // адресов в индексе.
        $response = $this->get('/city/moskva/koncerty');

        $response->assertStatus(404);
        $response->assertSee('пока нет мероприятий', false);
    }

    public function test_the_city_category_page_reports_a_missing_city_first(): void
    {
        $this->seedCategory('Концерты', 'koncerty');

        // Город проверяется первым: на несуществующий город сообщение о
        // категории уводило бы человека искать опечатку не в том сегменте.
        $this->get('/city/gorod-kotorogo-net/koncerty')
            ->assertStatus(404)
            ->assertSee('Город «gorod-kotorogo-net» не найден.', false);
    }

    public function test_the_city_category_page_reports_a_missing_category(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $this->seedCatalogEvent('Концерт', 'zal-fc-5', 'Москва', categoryId: $category);

        $this->get('/city/moskva/kategorii-kotoroy-net')
            ->assertStatus(404)
            ->assertSee('Категория «kategorii-kotoroy-net» не найдена.', false);
    }

    public function test_the_city_category_page_does_not_shadow_the_city_page(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $this->seedCatalogEvent('Концерт', 'zal-fc-6', 'Москва', categoryId: $category);

        // Двухсегментный `/city/{slug}` и трёхсегментный `/city/{slug}/{category}`
        // различаются числом сегментов, поэтому не пересекаются. Тест фиксирует
        // это: добавление фасета не должно было сломать уже проиндексированный
        // адрес города.
        $this->get('/city/moskva')->assertStatus(200)->assertSee('Афиша города Москва', false);
        $this->get('/city/moskva/koncerty')->assertStatus(200);
    }

    // ── Разметка ───────────────────────────────────────────────────────────

    public function test_the_city_category_breadcrumb_goes_through_the_real_city_page(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $this->seedCatalogEvent('Концерт', 'zal-bc-1', 'Москва', categoryId: $category);

        $html = (string) $this->get('/city/moskva/koncerty')->getContent();
        $elements = $this->breadcrumbList($html)['itemListElement'];

        // Три уровня: главная → город → «категория». Без среднего уровня
        // категория висела бы прямо под главной, то есть разметка сообщала бы
        // поисковику неверный путь к странице.
        $this->assertSame(['Главная', 'Афиша города Москва', 'Категория «Концерты»'], array_column($elements, 'name'));
        // Адрес среднего уровня — РЕАЛЬНАЯ страница города, а не подпись:
        // `item` крошки Google обходит, и битая ссылка обесценивает разметку.
        $this->assertSame(route('landing.city', ['slug' => 'moskva']), $elements[1]['item']);
    }

    public function test_the_city_category_breadcrumb_ends_at_the_canonical_address(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $this->seedCatalogEvent('Концерт', 'zal-bc-2', 'Москва', categoryId: $category);

        $html = (string) $this->get('/city/moskva/koncerty')->getContent();
        $elements = $this->breadcrumbList($html)['itemListElement'];
        $canonical = $this->canonicalOf($html);

        $this->assertSame(route('landing.city.category', ['slug' => 'moskva', 'category' => 'koncerty']), $canonical);
        // Требование Google: последняя позиция обязана совпадать с canonical.
        $this->assertSame($canonical, $elements[count($elements) - 1]['item'], 'последняя крошка не совпадает с canonical');
        $this->assertSame(range(1, count($elements)), array_column($elements, 'position'));
    }

    public function test_the_category_breadcrumb_is_two_levels_deep(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $this->seedCatalogEvent('Концерт', 'zal-bc-3', 'Москва', categoryId: $category);

        $html = (string) $this->get('/category/koncerty')->getContent();
        $elements = $this->breadcrumbList($html)['itemListElement'];

        $this->assertSame(['Главная', 'Афиша категории «Концерты»'], array_column($elements, 'name'));
        $this->assertSame(
            $this->canonicalOf($html),
            $elements[count($elements) - 1]['item'],
            'последняя крошка не совпадает с canonical',
        );
    }

    public function test_the_city_category_page_carries_an_item_list_of_its_events(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $event = $this->seedCatalogEvent('Симфония', 'zal-bc-4', 'Москва', categoryId: $category);

        $itemList = $this->jsonLdOfType((string) $this->get('/city/moskva/koncerty')->getContent(), 'ItemList');

        $this->assertNotNull($itemList, 'на странице нет ItemList');
        $this->assertSame($this->eventUrl($event), $itemList['itemListElement'][0]['url']);
        $this->assertSame(1, $itemList['itemListElement'][0]['position']);
    }

    public function test_the_category_page_links_to_neighbouring_categories(): void
    {
        $concerts = $this->seedCategory('Концерты', 'koncerty');
        $theatre = $this->seedCategory('Театр', 'teatr');

        $this->seedCatalogEvent('Концерт', 'zal-rel-c1', 'Москва', categoryId: $concerts);
        $this->seedCatalogEvent('Спектакль', 'zal-rel-c2', 'Казань', categoryId: $theatre);

        $html = (string) $this->get('/category/koncerty')->getContent();

        // Без исходящих ссылок страница категории — тупик: робот, попавший на неё
        // из выдачи, не находит соседние разделы каталога.
        $this->assertStringContainsString(route('landing.category', ['slug' => 'teatr']), $html);
    }

    public function test_the_city_category_page_links_only_to_categories_of_that_city(): void
    {
        $concerts = $this->seedCategory('Концерты', 'koncerty');
        $theatre = $this->seedCategory('Театр', 'teatr');
        $opera = $this->seedCategory('Опера', 'opera');

        // В Москве — концерты и опера. В Казани — театр и концерты.
        $this->seedCatalogEvent('Концерт в Москве', 'zal-rel-cc1', 'Москва', categoryId: $concerts);
        $this->seedCatalogEvent('Опера в Москве', 'zal-rel-cc4', 'Москва', categoryId: $opera);
        $this->seedCatalogEvent('Спектакль в Казани', 'zal-rel-cc2', 'Казань', categoryId: $theatre);
        $this->seedCatalogEvent('Концерт в Казани', 'zal-rel-cc3', 'Казань', categoryId: $concerts);

        $html = (string) $this->get('/city/moskva/koncerty')->getContent();

        // ПОЛОЖИТЕЛЬНЫЙ КОНТРОЛЬ. Соседняя категория того же города обязана быть
        // в перелинковке. Без него весь тест был бы зелёным и на странице, где
        // блока ссылок нет вовсе, — то есть проверял бы отсутствие, ничего не
        // проверив.
        $this->assertStringContainsString(
            route('landing.city.category', ['slug' => 'moskva', 'category' => 'opera']),
            $html,
        );

        // ОТРИЦАТЕЛЬНЫЙ. Театр есть только в Казани. Проверяются ОБЕ формы
        // адреса: если бы фасет взял общероссийский список категорий, ссылка
        // появилась бы именно в общероссийской форме — и одна проверка из двух
        // её бы не поймала.
        $this->assertStringNotContainsString(
            route('landing.city.category', ['slug' => 'moskva', 'category' => 'teatr']),
            $html,
        );
        $this->assertStringNotContainsString(route('landing.category', ['slug' => 'teatr']), $html);
    }

    public function test_a_category_page_is_indexable(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $this->seedCatalogEvent('Концерт', 'zal-idx-c', 'Москва', categoryId: $category);

        // `PreventIndexingOfServicePages` живёт в GLOBAL stack и закрывает
        // служебные пути заголовком `X-Robots-Tag: noindex, noarchive`; список
        // путей перечислен явно и пополняется вручную. Если `/category/*` туда
        // однажды попадёт (или список перевернут в «закрыть всё, кроме»),
        // страница будет отвечать 200 и присутствовать в карте, но в индексе её
        // не будет — и заметить это можно только такой проверкой.
        foreach (['/category/koncerty', '/city/moskva/koncerty'] as $path) {
            $response = $this->get($path);

            $response->assertStatus(200);
            $response->assertHeaderMissing('X-Robots-Tag');
        }
    }

    public function test_the_category_page_does_not_depend_on_the_built_storefront(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $this->seedCatalogEvent('Концерт', 'zal-build-c', 'Москва', categoryId: $category);

        config(['nabilet.storefront.shell_path' => base_path('dist/definitely-missing.html')]);

        $response = $this->get('/category/koncerty');

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

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
