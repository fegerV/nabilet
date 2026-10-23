<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Nabilet\Tests\Support\SellableSeatFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Канонический адрес события — одно правило на три потребителя.
 *
 * ЗАЧЕМ ЭТОТ ТЕСТ. Проверка «поле из БД годится только если ведёт на наш домен»
 * существовала ровно в одном месте — в `EventPageController`. Карта сайта и
 * JSON-LD брали `events.canonical_url` напрямую, то есть правило действовало
 * там, где вредит меньше всего. Последствия разные по тяжести:
 *
 *   — на странице чужой canonical снимает её с индексации как дубль
 *     («alternate page with proper canonical tag»);
 *   — в `<loc>` он нарушает требование протокола sitemap «адрес с того же
 *     хоста, что и карта»: Search Console отклоняет ВСЮ карту целиком, вместе
 *     со всеми остальными адресами;
 *   — в JSON-LD `url` он читается как «событие описано там», и rich snippet
 *     у нашей страницы пропадает.
 *
 * Проверяется поэтому не «метод вернул строку», а наблюдаемый результат во всех
 * трёх местах сразу: HTML страницы, XML карты и JSON-LD.
 *
 * ПОЧЕМУ ЗДЕСЬ, А НЕ В `tests/Unit`. Тест идёт через живой HTTP-контур: web-роут,
 * `RefreshDatabase` против настоящей схемы и контейнер, который собирает цепочку
 * `EventPageController → EventCanonicalUrl`. Замена `EventCanonicalUrl` на
 * «верну строку как есть» ломает его сразу в трёх assert'ах, а не в одном.
 */
final class EventCanonicalUrlTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        // Карта кешируется на час: без сброса тест зависел бы от того, какой из
        // тестов успел сходить на `/sitemap-events.xml` раньше.
        Cache::flush();
    }

    // ── фикстуры ────────────────────────────────────────────────────────────

    /**
     * Мероприятие + сеанс + позиция инвентаря, пригодные для страницы события.
     *
     * `published_at` обязателен: карта сайта не берёт события без него
     * (`SitemapService::getEventsSitemap()`), а фикстура его не ставит.
     *
     * @return array{org: int, event: int, session: int, inventory: int, slug: string, publicId: string}
     */
    private function seedEvent(): array
    {
        [$organizationId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat(available: 3);

        DB::table('events')->where('id', $eventId)->update([
            'published_at' => now()->subMinute(),
        ]);

        $row = DB::table('events')->where('id', $eventId)->first();

        return [
            'org' => (int) $organizationId,
            'event' => (int) $eventId,
            'session' => (int) $sessionId,
            'inventory' => (int) $inventoryId,
            'slug' => (string) $row->slug,
            'publicId' => (string) $row->public_id,
        ];
    }

    /** @param array<string, string> $values */
    private function setCanonical(int $eventId, array $values): void
    {
        DB::table('events')->where('id', $eventId)->update($values);
    }

    private function computedUrl(string $slug, string $publicId): string
    {
        return route('events.show', ['slug' => $slug, 'publicId' => $publicId]);
    }

    /** @return array<string, mixed> */
    private function jsonLd(TestResponse $response): array
    {
        $html = (string) $response->getContent();

        $this->assertSame(
            1,
            preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches),
            'в HTML нет блока JSON-LD'
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function eventPage(string $slug, string $publicId): TestResponse
    {
        $response = $this->get('/event/'.$slug.'/'.$publicId);
        $response->assertStatus(200);

        return $response;
    }

    private function sitemap(): string
    {
        Cache::flush();

        return (string) $this->get('/sitemap-events.xml')->getContent();
    }

    /** То же, но с произвольным хостом запроса (см. тест про стенд). */
    private function sitemapFrom(string $root): string
    {
        Cache::flush();

        return (string) $this->get($root.'/sitemap-events.xml')->getContent();
    }

    // ── страница: проверённый адрес принимается ─────────────────────────────

    public function test_a_same_host_stored_url_wins_on_the_page(): void
    {
        $event = $this->seedEvent();
        $stored = 'https://tickets.example.com/event/sobstvennyj-adres/01HZZ';

        $this->setCanonical($event['event'], ['canonical_url' => $stored]);

        $html = (string) $this->eventPage($event['slug'], $event['publicId'])->getContent();

        $this->assertStringContainsString($stored, $html);

        $jsonLd = $this->jsonLd($this->eventPage($event['slug'], $event['publicId']));
        $this->assertSame($stored, $jsonLd['url'], 'JSON-LD url не использует проверенный canonical');
    }

    public function test_a_relative_stored_url_is_absolutised_everywhere(): void
    {
        $event = $this->seedEvent();

        $this->setCanonical($event['event'], ['canonical_url' => '/event/otnositelnyj-adres']);

        $absolute = 'https://tickets.example.com/event/otnositelnyj-adres';

        $html = (string) $this->eventPage($event['slug'], $event['publicId'])->getContent();
        $this->assertStringContainsString($absolute, $html);

        $jsonLd = $this->jsonLd($this->eventPage($event['slug'], $event['publicId']));
        $this->assertSame($absolute, $jsonLd['url']);

        $this->assertStringContainsString($absolute, $this->sitemap());
    }

    public function test_a_stored_url_matching_the_request_host_is_accepted(): void
    {
        // Второй разрешённый хост — фактический хост ЗАПРОСА, а не `APP_URL`.
        // Это ровно случай стенда и локальной разработки: приложение открыли по
        // адресу, которого нет в `.env`, и сохранённый canonical обязан совпасть
        // с тем, что видит посетитель.
        //
        // Тестовый клиент по умолчанию шлёт запрос на `APP_URL`, поэтому здесь
        // адрес задаётся целиком — иначе хост запроса всегда совпадал бы с
        // `app.url` и вторая половина правила не проверялась бы никак.
        $event = $this->seedEvent();
        $stored = 'https://stand.example.com/event/adres-stenda';

        $this->setCanonical($event['event'], ['canonical_url' => $stored]);

        $this->assertStringContainsString($stored, $this->sitemapFrom('https://stand.example.com'));
        $this->assertStringContainsString(
            $stored,
            (string) $this->get('https://stand.example.com/event/'.$event['slug'].'/'.$event['publicId'])
                ->assertStatus(200)->getContent()
        );
    }

    // ── чужие домены и псевдо-схемы ─────────────────────────────────────────

    public function test_a_foreign_host_url_never_reaches_the_sitemap(): void
    {
        $event = $this->seedEvent();

        $this->setCanonical($event['event'], [
            'canonical_url' => 'https://not-our-domain.example/event/hijacked',
        ]);

        $xml = $this->sitemap();

        $this->assertStringNotContainsString(
            'not-our-domain.example',
            $xml,
            'чужой canonical попал в <loc> — протокол sitemap требует тот же хост, и Search Console отклонит всю карту'
        );
        $this->assertStringContainsString(
            htmlspecialchars($this->computedUrl($event['slug'], $event['publicId']), ENT_QUOTES | ENT_XML1),
            $xml,
            'карта не откатилась на вычисленный адрес'
        );
    }

    public function test_a_foreign_host_url_never_reaches_the_json_ld(): void
    {
        $event = $this->seedEvent();

        $this->setCanonical($event['event'], [
            'canonical_url' => 'https://not-our-domain.example/event/hijacked',
        ]);

        $jsonLd = $this->jsonLd($this->eventPage($event['slug'], $event['publicId']));

        $this->assertNotSame(
            'https://not-our-domain.example/event/hijacked',
            $jsonLd['url'],
            'JSON-LD url уехал на чужой домен — Google читает это как «событие описано там»'
        );
        $this->assertSame($this->computedUrl($event['slug'], $event['publicId']), $jsonLd['url']);
    }

    public function test_a_stored_url_matching_our_host_reaches_the_sitemap(): void
    {
        $event = $this->seedEvent();
        $stored = 'https://tickets.example.com/event/sobstvennyj-adres/01HZZ';

        $this->setCanonical($event['event'], ['canonical_url' => $stored]);

        $this->assertStringContainsString($stored, $this->sitemap());
    }

    /**
     * `mailto:` и `javascript:` — не адреса, но выглядят как непустая строка.
     *
     * У обоих `parse_url()` не находит хост, поэтому раньше они проходили ветку
     * «относительный путь» и попадали в `<loc>` как есть. Для `javascript:` это
     * ещё и запись в XML-документ, который отдаётся краулеру.
     */
    #[DataProvider('schemeOnlyUrls')]
    public function test_a_scheme_only_url_is_rejected(string $stored): void
    {
        $event = $this->seedEvent();

        $this->setCanonical($event['event'], ['canonical_url' => $stored]);

        $jsonLd = $this->jsonLd($this->eventPage($event['slug'], $event['publicId']));
        $this->assertSame($this->computedUrl($event['slug'], $event['publicId']), $jsonLd['url']);

        $this->assertStringNotContainsString($stored, $this->sitemap());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function schemeOnlyUrls(): array
    {
        return [
            'mailto' => ['mailto:info@example.com'],
            'javascript' => ['javascript:alert(1)'],
        ];
    }

    public function test_an_empty_canonical_falls_back_to_the_computed_url(): void
    {
        $event = $this->seedEvent();

        $this->setCanonical($event['event'], ['canonical_url' => '']);

        $jsonLd = $this->jsonLd($this->eventPage($event['slug'], $event['publicId']));
        $this->assertSame($this->computedUrl($event['slug'], $event['publicId']), $jsonLd['url']);
    }

    // ── JSON-LD: картинки через единственный владелец корней ────────────────

    public function test_event_images_are_built_by_asset_url(): void
    {
        $event = $this->seedEvent();

        $this->setCanonical($event['event'], ['poster' => 'posters/afisha.jpg']);

        $jsonLd = $this->jsonLd($this->eventPage($event['slug'], $event['publicId']));

        $this->assertSame(
            ['https://tickets.example.com/storage/posters/afisha.jpg'],
            $jsonLd['image'],
            'путь постера не превращён в абсолютный URL корня /storage/'
        );
    }

    public function test_an_absolute_image_url_is_passed_through(): void
    {
        $event = $this->seedEvent();

        $this->setCanonical($event['event'], ['poster' => 'https://cdn.example.com/afisha.jpg']);

        $jsonLd = $this->jsonLd($this->eventPage($event['slug'], $event['publicId']));

        $this->assertSame(['https://cdn.example.com/afisha.jpg'], $jsonLd['image']);
    }
}
