<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nabilet\Modules\Events\Domain\EventStatus;
use Nabilet\Tests\Support\CatalogFixtures;
use Tests\TestCase;

/**
 * Карты площадок, городов, категорий и организаторов (SEO-аудит P-12).
 *
 * ЧТО ЗДЕСЬ ГЛАВНОЕ. Не «эндпоинт отвечает 200», а ГЛАВНЫЙ ИНВАРИАНТ КАРТЫ:
 * каждый адрес в ней обязан отвечать 200. Карта, перечисляющая несуществующие
 * страницы, — не мелкий недочёт: Google помечает такие адреса как ошибки и
 * перестаёт доверять карте целиком, а не отдельным записям. Именно на этом
 * сломался предыдущий эндпоинт площадок: он указывал на `route('venues.show')`,
 * которого не существовало, и отвечал 500.
 *
 * Поэтому здесь есть проверки, которые обходят КАЖДЫЙ адрес из карты и сверяют
 * его статус. Это единственный способ поймать расхождение между «что считает
 * подходящим SQL в `CatalogService`» и «что реально отдаёт `LandingController`»:
 * фильтры в этих двух местах написаны отдельно, и разойтись они могут молча.
 *
 * ЭТО ОСОБЕННО ВЕРНО ДЛЯ ОРГАНИЗАТОРОВ. `organizations()` идёт через
 * `DB::table()` и обязана сама фильтровать мягко удалённые строки — глобального
 * скоупа `SoftDeletes` там нет. Проверка «каждый адрес отвечает 200» ловит эту
 * ошибку напрямую: удалённая организация попадёт в карту, а её страница ответит
 * 404, и тест упадёт на несоответствии.
 */
final class LandingSitemapTest extends TestCase
{
    use CatalogFixtures;
    use RefreshDatabase;

    // ── Содержимое карт ────────────────────────────────────────────────────

    public function test_the_venues_sitemap_lists_venues_that_have_events(): void
    {
        $this->seedCatalogEvent('Концерт', 'kreml', 'Москва');

        $xml = $this->venuesSitemap();

        $this->assertStringContainsString(
            route('landing.venue', ['slug' => 'kreml']),
            $xml,
        );
        $this->assertStringContainsString('<lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
    }

    public function test_the_venues_sitemap_skips_venues_without_public_events(): void
    {
        // Площадка существует, но её единственное событие — черновик: страница
        // такой площадки отвечает 404, и адрес в карте был бы обещанием, которое
        // карта не выполняет.
        $this->seedCatalogEvent('Черновик', 'pustoy', 'Москва', status: EventStatus::DRAFT);

        $this->assertStringNotContainsString(
            route('landing.venue', ['slug' => 'pustoy']),
            $this->venuesSitemap(),
        );
    }

    public function test_the_cities_sitemap_lists_cities_that_have_events(): void
    {
        $this->seedCatalogEvent('Концерт', 'zal-1', 'Екатеринбург');

        $xml = $this->citiesSitemap();

        $this->assertStringContainsString(
            route('landing.city', ['slug' => 'ekaterinburg']),
            $xml,
        );
        // Город — хаб раздела, он подвижнее отдельной площадки.
        $this->assertStringContainsString('<changefreq>daily</changefreq>', $xml);
    }

    public function test_the_cities_sitemap_merges_spellings_into_one_address(): void
    {
        $this->seedCatalogEvent('Джаз', 'zal-2', 'Санкт-Петербург');
        $this->seedCatalogEvent('Рок', 'zal-3', 'Санкт Петербург');

        $xml = $this->citiesSitemap();

        // Одно написание — один адрес. Два варианта одного города дали бы две
        // страницы-полудубля в индексе.
        $this->assertSame(
            1,
            substr_count($xml, route('landing.city', ['slug' => 'sankt-peterburg'])),
            'город попал в карту больше одного раза',
        );
    }

    public function test_the_index_points_at_the_venue_and_city_sitemaps(): void
    {
        $xml = $this->indexXml();

        $this->assertStringContainsString(route('sitemap.venues'), $xml);
        $this->assertStringContainsString(route('sitemap.cities'), $xml);
    }

    public function test_the_categories_sitemap_lists_categories_that_have_events(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $this->seedCatalogEvent('Концерт', 'zal-cat-sm', 'Москва', categoryId: $category);

        $xml = $this->categoriesSitemap();

        $this->assertStringContainsString(route('landing.category', ['slug' => 'koncerty']), $xml);
        $this->assertStringContainsString('<lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
    }

    public function test_the_categories_sitemap_skips_categories_without_public_events(): void
    {
        $this->seedCategory('Пустая', 'pustaya');

        // Категория существует, но её страница отвечает 404 — адрес в карте был
        // бы обещанием, которое карта не выполняет. Ровно на этом сломался
        // предыдущий эндпоинт площадок.
        $this->assertStringNotContainsString(
            route('landing.category', ['slug' => 'pustaya']),
            $this->categoriesSitemap(),
        );
    }

    public function test_the_categories_sitemap_skips_inactive_categories(): void
    {
        $category = $this->seedCategory('Закрытая', 'zakrytaya', 'inactive');
        $this->seedCatalogEvent('Концерт', 'zal-cat-off', 'Москва', categoryId: $category);

        $this->assertStringNotContainsString(
            route('landing.category', ['slug' => 'zakrytaya']),
            $this->categoriesSitemap(),
        );
    }

    public function test_the_index_points_at_the_categories_sitemap(): void
    {
        $this->assertStringContainsString(route('sitemap.categories'), $this->indexXml());
    }

    // ── Карта организаторов ────────────────────────────────────────────────

    public function test_the_organizers_sitemap_lists_organizers_that_have_events(): void
    {
        $event = $this->seedCatalogEvent('Концерт', 'zal-org-sm', 'Сургут');
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        $xml = $this->organizersSitemap();

        $this->assertStringContainsString(route('landing.organizer', ['slug' => 'surgut-koncert']), $xml);
        $this->assertStringContainsString('<lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
    }

    public function test_the_organizers_sitemap_skips_organizers_without_public_events(): void
    {
        // Организация существует, но её единственное событие — черновик: её
        // страница отвечает 404, и адрес в карте был бы обещанием, которое карта
        // не выполняет.
        $event = $this->seedCatalogEvent('Черновик', 'zal-org-off', 'Сургут', status: EventStatus::DRAFT);
        $this->seedOrganization($event['org'], 'pustaya-org', 'Пустая организация');

        $this->assertStringNotContainsString(
            route('landing.organizer', ['slug' => 'pustaya-org']),
            $this->organizersSitemap(),
        );
    }

    public function test_the_organizers_sitemap_skips_inactive_organizations(): void
    {
        $inactive = $this->seedCatalogEvent('Концерт', 'zal-org-in', 'Сургут');
        $this->seedOrganization($inactive['org'], 'zakrytaya-org', 'Закрытая', 'inactive');

        // Организация снята с публикации: её страница отвечает 404, поэтому и в
        // карту она попадать не должна.
        //
        // МЯГКО УДАЛЁННОЙ ОРГАНИЗАЦИИ ЗДЕСЬ БЫТЬ НЕ МОЖЕТ, и это не упущение.
        // Колонки `organizations.deleted_at` в схеме нет (проверено на живой
        // базе: `deleted_at` есть у `events`, `media_assets`, `promo_codes`,
        // `users` и `event_dates`, но не у `organizations`), поэтому мягкого
        // удаления у организаций нет вовсе — с публикации снимают статусом.
        // Первая версия этого теста выставляла `deleted_at` напрямую и падала с
        // `SQLSTATE[42S22]`; вместе с ней падала и сама страница организатора,
        // потому что дубликат модели в `Modules\Organizations\Models` нёс трейт
        // `SoftDeletes`. См. `LandingOrganizationTest::test_the_organization_model_matches_the_schema`.
        $this->assertStringNotContainsString(
            route('landing.organizer', ['slug' => 'zakrytaya-org']),
            $this->organizersSitemap(),
        );
    }

    public function test_the_index_points_at_the_organizers_sitemap(): void
    {
        $this->assertStringContainsString(route('sitemap.organizers'), $this->indexXml());
    }

    // ── Инвариант «адрес из карты отвечает 200» ────────────────────────────

    public function test_every_address_in_the_venues_sitemap_answers_200(): void
    {
        $this->seedCatalogEvent('Опера', 'zal-live', 'Казань');
        $this->seedCatalogEvent('Отменённый', 'zal-dead', 'Казань', status: EventStatus::CANCELLED);

        foreach ($this->locs($this->venuesSitemap()) as $loc) {
            $this->get($loc)->assertStatus(200);
        }
    }

    public function test_every_address_in_the_cities_sitemap_answers_200(): void
    {
        $this->seedCatalogEvent('Опера', 'zal-live-2', 'Казань');

        foreach ($this->locs($this->citiesSitemap()) as $loc) {
            $this->get($loc)->assertStatus(200);
        }
    }

    public function test_every_address_in_the_categories_sitemap_answers_200(): void
    {
        $live = $this->seedCategory('Концерты', 'koncerty');
        $draftOnly = $this->seedCategory('Черновики', 'chernoviki');

        $this->seedCatalogEvent('Концерт', 'zal-live-c1', 'Казань', categoryId: $live);
        // У категории есть событие, но оно не публично: её страница отвечает
        // 404, поэтому и в карту она попадать не должна.
        $this->seedCatalogEvent('Черновик', 'zal-live-c2', 'Казань', status: EventStatus::DRAFT, categoryId: $draftOnly);

        foreach ($this->locs($this->categoriesSitemap()) as $loc) {
            $this->get($loc)->assertStatus(200);
        }
    }

    public function test_every_address_in_the_organizers_sitemap_answers_200(): void
    {
        $live = $this->seedCatalogEvent('Концерт', 'zal-live-o1', 'Сургут');
        $this->seedOrganization($live['org'], 'surgut-koncert', 'Сургут-Концерт');

        // У второго организатора событие не публично: его страница отвечает 404,
        // поэтому и в карту он попадать не должен.
        $draftOnly = $this->seedCatalogEvent('Черновик', 'zal-live-o2', 'Сургут', status: EventStatus::DRAFT);
        $this->seedOrganization($draftOnly['org'], 'chernovaya-org', 'Черновая организация');

        $locs = $this->locs($this->organizersSitemap());

        $this->assertNotContains(
            parse_url(route('landing.organizer', ['slug' => 'chernovaya-org']), PHP_URL_PATH),
            $locs,
        );

        foreach ($locs as $loc) {
            $this->get($loc)->assertStatus(200);
        }
    }

    // ── lastmod ────────────────────────────────────────────────────────────

    public function test_the_landing_sitemaps_carry_a_lastmod_that_is_not_in_the_future(): void
    {
        $category = $this->seedCategory('Концерты', 'koncerty');
        $event = $this->seedCatalogEvent('Концерт', 'zal-lm', 'Оренбург', categoryId: $category);
        $this->seedOrganization($event['org'], 'surgut-koncert', 'Сургут-Концерт');

        $moment = Carbon::parse('2026-02-03 08:15:00');

        // Дату правки двигаем ВСЕМ источникам: у площадки и организатора её даёт
        // `GREATEST` из правки самой строки и правок её событий, поэтому без
        // правки строки `venues`/`organizations` значение осталось бы равным
        // моменту создания фикстуры (`now()`), и тест проверял бы не то. У
        // категории своего `updated_at` нет вовсе (`$timestamps = false`),
        // поэтому её дата — только из события.
        DB::table('events')->where('id', $event['event'])->update(['updated_at' => $moment]);
        DB::table('venues')->where('id', $event['venue'])->update(['updated_at' => $moment]);
        DB::table('organizations')->where('id', $event['org'])->update(['updated_at' => $moment]);
        Cache::flush();

        foreach ([
            $this->venuesSitemap(),
            $this->citiesSitemap(),
            $this->categoriesSitemap(),
            $this->organizersSitemap(),
        ] as $xml) {
            $lastmod = Carbon::parse($this->firstLastmod($xml));

            $this->assertFalse(
                $lastmod->isFuture(),
                'lastmod в будущем обесценивает поле для всего файла карты',
            );
            $this->assertSame($moment->toIso8601String(), $lastmod->toIso8601String());
        }
    }

    // ── Кеш ────────────────────────────────────────────────────────────────

    public function test_the_landing_sitemaps_are_cached(): void
    {
        $this->get('/api/v1/sitemap-venues.xml')->assertStatus(200);
        $this->get('/api/v1/sitemap-cities.xml')->assertStatus(200);
        $this->get('/api/v1/sitemap-categories.xml')->assertStatus(200);
        $this->get('/api/v1/sitemap-organizers.xml')->assertStatus(200);

        $this->assertTrue(Cache::has('seo:sitemap:venues'), 'карта площадок не попала в кеш');
        $this->assertTrue(Cache::has('seo:sitemap:cities'), 'карта городов не попала в кеш');
        $this->assertTrue(Cache::has('seo:sitemap:categories'), 'карта категорий не попала в кеш');
        $this->assertTrue(Cache::has('seo:sitemap:organizers'), 'карта организаторов не попала в кеш');
    }

    // ── Вспомогательное ────────────────────────────────────────────────────

    private function venuesSitemap(): string
    {
        $response = $this->get('/api/v1/sitemap-venues.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        return (string) $response->getContent();
    }

    private function citiesSitemap(): string
    {
        $response = $this->get('/api/v1/sitemap-cities.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        return (string) $response->getContent();
    }

    private function categoriesSitemap(): string
    {
        $response = $this->get('/api/v1/sitemap-categories.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        return (string) $response->getContent();
    }

    private function organizersSitemap(): string
    {
        $response = $this->get('/api/v1/sitemap-organizers.xml');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        return (string) $response->getContent();
    }

    private function indexXml(): string
    {
        $response = $this->get('/api/v1/sitemap.xml');

        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    /**
     * @return list<string> ПУТИ из `<loc>` (без схемы и хоста)
     */
    private function locs(string $xml): array
    {
        preg_match_all('#<loc>([^<]+)</loc>#', $xml, $matches);

        // В карте обязан быть хотя бы один адрес: пустой список сделал бы
        // проверку «каждый адрес отвечает 200» тождественно истинной, то есть
        // зелёной ровно тогда, когда карта сломана.
        $this->assertNotSame([], $matches[1], 'в карте нет ни одного адреса');

        return array_map(
            static fn (string $loc): string => (string) parse_url($loc, PHP_URL_PATH),
            $matches[1],
        );
    }

    private function firstLastmod(string $xml): string
    {
        $found = preg_match('#<lastmod>([^<]+)</lastmod>#', $xml, $matches);

        $this->assertSame(1, $found, 'в карте нет lastmod');

        return $matches[1];
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
