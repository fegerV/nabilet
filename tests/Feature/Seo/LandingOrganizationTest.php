<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nabilet\Modules\Core\Organizations\Models\Organization;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Tests\Support\CatalogFixtures;
use Tests\TestCase;

/**
 * Посадочная страница организатора — `/organizer/{slug}` (SEO-аудит P-12).
 *
 * ПОЧЕМУ ОТДЕЛЬНЫЙ ФАЙЛ. У организатора другой предмет, чем у площадки и города:
 * площадка отвечает «что здесь идёт», организатор — «что проводит этот
 * организатор». Из этого следует всё остальное, и каждое следствие закреплено
 * здесь отдельным тестом:
 *
 *   • организатор — фасет КАТАЛОГА, а не место: сеанс для его событий не
 *     требуется, а события собираются со всех площадок и городов. Набор обязан
 *     совпадать с набором публичного каталога (`GET /api/v1/events`), который
 *     умеет фильтровать по `organization_id` и фильтрует только по статусу;
 *   • у организатора, в отличие от города, есть СВОИ поля — название, описание,
 *     логотип, контакты, — и они попадают в разметку `Organization`. Города как
 *     сущности в схеме нет вовсе (`venues.city` — свободный текст), поэтому
 *     странице города разметки сущности не бывает;
 *   • организатор СКРЫВАЕТ чужие события: это первая из посадочных страниц,
 *     у которой есть содержательный отрицательный случай. У страницы города
 *     «чужих» событий не бывает по построению.
 *
 * АДРЕСА НАПИСАНЫ ЯВНО, а не через `Str::slug()`: адрес — публичный контракт, и
 * его изменение обязано быть видно в диффе теста, а не приходить незаметно из
 * обновления библиотеки транслитерации. В отличие от категорий, здесь адрес
 * достижим в любом живом развёртывании: организация создаётся установщиком
 * (`InstallerController::bootstrapOrganization()`) со slug'ом из названия, и без
 * неё в системе нет ни одного события.
 */
final class LandingOrganizationTest extends TestCase
{
    use CatalogFixtures;
    use RefreshDatabase;

    // ── Содержимое страницы ────────────────────────────────────────────────

    public function test_an_organizer_page_lists_its_events_with_links(): void
    {
        $event = $this->seedCatalogEvent('Рок-концерт', 'zal-org-1', 'Сургут');
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        $response = $this->get('/organizer/surgut-koncert');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('Афиша организатора «Сургут-Концерт»', false);
        $response->assertSee('Рок-концерт', false);
        $response->assertSee('href="' . $this->eventUrl($event) . '"', false);
    }

    public function test_an_organizer_page_gathers_events_of_every_city(): void
    {
        $first = $this->seedCatalogEvent('Концерт в Сургуте', 'zal-org-2', 'Сургут');
        $this->seedOrganization($first['org'], 'surgut-koncert', 'Сургут-Концерт');

        // Второе событие создаётся с СВОЕЙ организацией — так устроена фикстура,
        // потому что организация это первое звено цепочки внешних ключей. Затем
        // событие переводится в первую организацию: «у организатора несколько
        // мероприятий» и есть основной сценарий этой страницы.
        $second = $this->seedCatalogEvent('Концерт в Тюмени', 'zal-org-3', 'Тюмень');
        $this->moveEventToOrganization($second['event'], $first['org']);

        $response = $this->get('/organizer/surgut-koncert');

        $response->assertStatus(200);
        $response->assertSee('href="' . $this->eventUrl($first) . '"', false);
        $response->assertSee('href="' . $this->eventUrl($second) . '"', false);
    }

    public function test_an_organizer_page_does_not_show_another_organizers_events(): void
    {
        $mine = $this->seedCatalogEvent('Мой концерт', 'zal-org-4', 'Сургут');
        $this->seedOrganization($mine['org'], 'surgut-koncert', 'Сургут-Концерт');

        $theirs = $this->seedCatalogEvent('Чужой концерт', 'zal-org-5', 'Сургут');
        $this->seedOrganization($theirs['org'], 'drugaya-org', 'Другая организация');

        $response = $this->get('/organizer/surgut-koncert');

        $response->assertStatus(200);
        $response->assertSee('href="' . $this->eventUrl($mine) . '"', false);
        // ОТРИЦАТЕЛЬНЫЙ СЛУЧАЙ — то, что делает тест осмысленным: проверка
        // только на присутствие своего события прошла бы и на странице, которая
        // показывает вообще всё.
        $response->assertDontSee($this->eventUrl($theirs), false);
        $response->assertDontSee('Чужой концерт', false);
    }

    public function test_an_organizer_page_shows_an_event_that_has_no_session(): void
    {
        $event = $this->seedCatalogEvent('Без сеанса', 'zal-org-6', 'Сургут');
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        // Сеанс удаляем — событие остаётся опубликованным. Публичный каталог
        // фильтруется только по статусу, поэтому событие в нём есть; страница
        // организатора обязана показывать тот же набор. Город и площадка,
        // наоборот, требуют сеанс: там нечего было бы показать.
        DB::table('sessions')->where('id', $event['session'])->delete();
        Cache::flush();

        $this->get('/organizer/surgut-koncert')->assertStatus(200)->assertSee('Без сеанса', false);
        $this->get('/city/surgut')->assertStatus(404);
    }

    public function test_an_organizer_without_events_answers_404(): void
    {
        // Организация есть, но её единственное событие — черновик.
        $event = $this->seedCatalogEvent('Черновик', 'zal-org-7', 'Сургут', status: EventStatus::DRAFT);
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        $response = $this->get('/organizer/surgut-koncert');

        $response->assertStatus(404);
        $response->assertSee('пока нет мероприятий', false);
    }

    public function test_an_unknown_organizer_slug_answers_a_branded_404(): void
    {
        $response = $this->get('/organizer/organizatora-kotorogo-net');

        $response->assertStatus(404);
        $response->assertSee('Страница не найдена', false);
        $response->assertSee('href="/"', false);
    }

    public function test_an_inactive_organizer_has_no_page(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-org-8', 'Сургут');
        $this->seedOrganization($event['org'], 'zakrytaya-org', 'Закрытая организация', 'inactive');

        // Страница снятой с публикации организации не должна открываться: иначе
        // в индексе остаётся адрес сущности, которую администратор закрыл.
        $this->get('/organizer/zakrytaya-org')->assertStatus(404);
    }

    public function test_the_organization_model_matches_the_schema(): void
    {
        // `organizations` НЕ имеет колонки `deleted_at` — ни в миграции
        // (`2026_09_20_000100_001_identity.php`), ни в спецификации. Модель с
        // трейтом `SoftDeletes` добавляла бы `where organizations.deleted_at
        // is null` в КАЖДЫЙ запрос и падала бы с `SQLSTATE[42S22] Column not
        // found: 1054` — то есть страница организатора отвечала бы 500 на живой
        // базе, а не 404 на несуществующем адресе.
        //
        // ИМЕННО ЭТО И СЛУЧИЛОСЬ ПРИ ПЕРВОМ ПРОГОНЕ ЭТОГО ФАЙЛА. Причина
        // оказалась не в коде страницы: в проекте ДВА класса `Organization`, и
        // дубликат в `Modules\Organizations\Models` нёс этот трейт. Поэтому
        // проверяются ОБА класса, а не только тот, что импортирует Seo: иначе
        // тест закрепил бы одну половину проблемы и пропустил вторую.
        $this->assertFalse(
            Schema::hasColumn('organizations', 'deleted_at'),
            'колонка появилась — решите, нужны ли теперь SoftDeletes и фильтрация удалённых',
        );

        foreach ([
            Organization::class,
            \Nabilet\Modules\Organizations\Models\Organization::class,
        ] as $class) {
            $this->assertArrayNotHasKey(
                SoftDeletes::class,
                class_uses_recursive($class),
                sprintf('%s снова использует SoftDeletes при отсутствующей колонке deleted_at', $class),
            );
        }

        // И главное: запрос через модель обязан работать, а страница — отвечать.
        $event = $this->seedCatalogEvent('Концерт', 'zal-schema-org', 'Сургут');
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        $this->get('/organizer/surgut-koncert')->assertStatus(200);
    }

    public function test_a_soft_deleted_event_leaves_the_organizer_page(): void
    {
        $event = $this->seedCatalogEvent('Удалённый', 'zal-org-10', 'Сургут');
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        DB::table('events')->where('id', $event['event'])->update(['deleted_at' => now()]);
        Cache::flush();

        $this->get('/organizer/surgut-koncert')->assertStatus(404);
    }

    // ── Разметка ───────────────────────────────────────────────────────────

    public function test_the_organizer_page_carries_an_organization_block(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-org-11', 'Сургут');
        $this->seedOrganization(
            $event['org'],
            'surgut-koncert',
            'Сургут-Концерт',
            description: 'Организатор концертов в Сургуте',
            logo: 'logos/surgut-koncert.png',
            email: 'box@surgut-koncert.example',
            phone: '+7 3462 00-00-00',
        );

        $block = $this->jsonLdOfType((string) $this->get('/organizer/surgut-koncert')->getContent(), 'Organization');

        $this->assertNotNull($block, 'на странице организатора нет Organization');
        $this->assertSame('https://schema.org', $block['@context']);
        $this->assertSame('Сургут-Концерт', $block['name']);
        $this->assertSame(route('landing.organizer', ['slug' => 'surgut-koncert']), $block['url']);
        $this->assertSame('Организатор концертов в Сургуте', $block['description']);
        $this->assertSame('box@surgut-koncert.example', $block['email']);
        $this->assertSame('+7 3462 00-00-00', $block['phone']);
        // Логотип проходит через `AssetUrl`, а не склеивается в шаблоне: в
        // проекте два разных корня (`/storage/...` для загруженных файлов и `/`
        // для статики приложения), и ручная склейка уже однажды дала `og:image`
        // со ссылкой в 404. Хост в ассерте не фиксируется — он свой на каждом
        // стенде, и проверяется именно корень.
        $this->assertStringContainsString('/storage/logos/surgut-koncert.png', $block['logo']);
        $this->assertNoEmptyScalars($block);
    }

    public function test_the_organization_block_omits_absent_fields(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-org-12', 'Сургут');
        // Описание, логотип и контакты не заполнены — обычное состояние: их
        // проставляют позже, а событие публикуют раньше.
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        $block = $this->jsonLdOfType((string) $this->get('/organizer/surgut-koncert')->getContent(), 'Organization');

        $this->assertNotNull($block, 'на странице организатора нет Organization');

        // «Нет данных» обязано выглядеть как ОТСУТСТВИЕ КЛЮЧА, а не как пустая
        // строка: валидатор Google считает `""` значением и ругается на неполное
        // поле, тогда как отсутствие ключа ошибкой не является.
        foreach (['description', 'logo', 'email', 'phone'] as $absent) {
            $this->assertArrayNotHasKey($absent, $block, sprintf('пустое поле %s осталось в разметке', $absent));
        }

        $this->assertNoEmptyScalars($block);
    }

    public function test_the_organizer_breadcrumb_is_two_levels_deep(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-org-13', 'Сургут');
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        $html = (string) $this->get('/organizer/surgut-koncert')->getContent();
        $elements = $this->breadcrumbList($html)['itemListElement'];

        $this->assertSame(['Главная', 'Афиша организатора «Сургут-Концерт»'], array_column($elements, 'name'));
        // Требование Google: последняя позиция обязана совпадать с canonical,
        // иначе разметка отбрасывается целиком.
        $this->assertSame(
            $this->canonicalOf($html),
            $elements[count($elements) - 1]['item'],
            'последняя крошка не совпадает с canonical',
        );
        $this->assertSame(range(1, count($elements)), array_column($elements, 'position'));
    }

    public function test_the_organizer_page_carries_an_item_list_of_its_events(): void
    {
        $event = $this->seedCatalogEvent('Симфония', 'zal-org-14', 'Сургут');
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        $itemList = $this->jsonLdOfType((string) $this->get('/organizer/surgut-koncert')->getContent(), 'ItemList');

        $this->assertNotNull($itemList, 'на странице нет ItemList');
        $this->assertSame($this->eventUrl($event), $itemList['itemListElement'][0]['url']);
        $this->assertSame(1, $itemList['itemListElement'][0]['position']);
    }

    public function test_the_organizer_page_links_to_neighbouring_organizers(): void
    {
        $mine = $this->seedCatalogEvent('Концерт', 'zal-rel-o1', 'Сургут');
        $this->seedOrganization($mine['org'], 'surgut-koncert', 'Сургут-Концерт');

        $other = $this->seedCatalogEvent('Опера', 'zal-rel-o2', 'Тюмень');
        $this->seedOrganization($other['org'], 'tyumen-opera', 'Тюменская опера');

        // Третий организатор существует, но публичных событий у него нет: его
        // страница отвечает 404, поэтому соседом он быть не может.
        $empty = $this->seedCatalogEvent('Черновик', 'zal-rel-o3', 'Тюмень', status: EventStatus::DRAFT);
        $this->seedOrganization($empty['org'], 'chernovaya-org', 'Черновая организация');

        $html = (string) $this->get('/organizer/surgut-koncert')->getContent();

        // ПОЛОЖИТЕЛЬНЫЙ КОНТРОЛЬ: без него весь тест был бы зелёным и на
        // странице, где блока ссылок нет вовсе, — то есть проверял бы
        // отсутствие, ничего не проверив.
        $this->assertStringContainsString(route('landing.organizer', ['slug' => 'tyumen-opera']), $html);
        // ОТРИЦАТЕЛЬНЫЙ: ссылка на 404 в перелинковке — та же ошибка, что адрес
        // в карте сайта, ведущий на 404.
        $this->assertStringNotContainsString(route('landing.organizer', ['slug' => 'chernovaya-org']), $html);
    }

    public function test_an_organizer_page_is_indexable(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-org-15', 'Сургут');
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        // `PreventIndexingOfServicePages` живёт в GLOBAL stack и закрывает
        // служебные пути заголовком `X-Robots-Tag: noindex, noarchive`; список
        // путей перечислен явно и пополняется вручную. Если `/organizer/*` туда
        // однажды попадёт (или список перевернут в «закрыть всё, кроме»),
        // страница будет отвечать 200 и присутствовать в карте, но в индексе её
        // не будет — и заметить это можно только такой проверкой.
        $response = $this->get('/organizer/surgut-koncert');

        $response->assertStatus(200);
        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_the_organizer_page_does_not_depend_on_the_built_storefront(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-org-16', 'Сургут');
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        config(['nabilet.storefront.shell_path' => base_path('dist/definitely-missing.html')]);

        $response = $this->get('/organizer/surgut-koncert');

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

    /**
     * Ни одного пустого значения в разметке — рекурсивно.
     *
     * ЭТО ПРОВЕРКА РЕГРЕССИИ НА `pruneEmpty`, а не украшение (критерий 20
     * SEO-аудита). Валидатор Google считает пустую строку ЗНАЧЕНИЕМ и ругается
     * на неполное поле: `"email": ""` — ошибка, тогда как отсутствие ключа
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
