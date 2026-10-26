<?php

declare(strict_types=1);

namespace Tests\Feature\Venues;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Tests\TestCase;

/**
 * Импорт площадки со страницы события Яндекс.Афиши.
 *
 * Контракт VenueImportController::parse:
 *   1. JSON-LD страницы разбирается в предпросмотр (событие + площадка);
 *      создание записи — за обычным POST /venues, эндпоинт ничего не пишет.
 *   2. Хост жёстко ограничен afisha.yandex.ru (SSRF-защита) — 422.
 *   3. Страница без карточки события — 422 AFISHA_PARSE_FAILED.
 *   4. Недоступная страница — 422 AFISHA_UNREACHABLE.
 */
final class VenueAfishaImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $now = now();

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = DB::table('roles')->where('slug', 'admin')->value('id');

        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
            ]);
        }

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        $this->actingAs(User::query()->findOrFail($userId), 'api');
    }

    private function eventPageHtml(): string
    {
        return <<<'HTML'
        <!doctype html><html><head>
        <script type="application/ld+json">{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Сургут"}]}</script>
        <script type="application/ld+json">{"@context":"https://schema.org","@type":"MusicEvent","name":"Шура","description":"Феерия русского поп-рока","url":"https://afisha.yandex.ru/surgut/concert/shura-2026-10-30","image":"https://avatars.mds.yandex.net/orig","startDate":"2026-10-29T21:00:00.000Z","location":{"@type":"Place","name":"Вавилон","address":{"@type":"PostalAddress","streetAddress":"ул. Университетская, 55","addressLocality":"Сургут"},"geo":{"@type":"GeoCoordinates","latitude":61.263354,"longitude":73.432163},"telephone":["+7 (3462) 94-40-48"]},"offers":{"@type":"AggregateOffer","lowPrice":"2500.00","highPrice":"12000.00","priceCurrency":"RUB"}}</script>
        </head><body><script>window.__APOLLO_STATE__={"Session:1":{"ticket":{"__ref":"Ticket:1"}},"Ticket:1":{"id":"843017","price":{"__typename":"TicketPriceRange","currency":"rub","min":230000,"max":280000},"saleStatus":"available"}}</script></body></html>
        HTML;
    }

    public function test_parses_event_page_into_a_preview(): void
    {
        Http::fake([
            'afisha.yandex.ru/*' => Http::response($this->eventPageHtml(), 200),
        ]);

        $this->postJson('/api/v1/venues/import-afisha', [
            'url' => 'https://afisha.yandex.ru/surgut/concert/shura-2026-10-30',
        ])
            ->assertOk()
            ->assertJsonPath('data.event.title', 'Шура')
            ->assertJsonPath('data.event.starts_at', '2026-10-29T21:00:00.000Z')
            // Цены ОБЕИХ веток — в minor units (ТЗ §3), как и всё остальное API.
            // JSON-LD присылает рубли строкой, SSR-стейт — копейки числом; до
            // `ExternalPrice` эти две ветки отдавали разные масштабы.
            ->assertJsonPath('data.event.price_min', 250000)
            ->assertJsonPath('data.event.price_max', 1200000)
            ->assertJsonPath('data.event.price_currency', 'RUB')
            ->assertJsonPath('data.venue.name', 'Вавилон')
            ->assertJsonPath('data.venue.city', 'Сургут')
            ->assertJsonPath('data.venue.address', 'ул. Университетская, 55')
            ->assertJsonPath('data.venue.latitude', 61.263354)
            ->assertJsonPath('data.venue.phones.0', '+7 (3462) 94-40-48')
            // Сеанс и цены из SSR-стейта (TicketPriceRange): копейки остаются
            // копейками — 230000, а не 2300 «рублей» с потерянным остатком.
            ->assertJsonPath('data.ticket.id', '843017')
            ->assertJsonPath('data.ticket.price_min', 230000)
            ->assertJsonPath('data.ticket.price_max', 280000)
            ->assertJsonPath('data.ticket.price_currency', 'RUB');

        // Эндпоинт — чистый разбор: площадок после вызова не прибавилось.
        $this->assertDatabaseCount('venues', 0);
    }

    public function test_rejects_urls_outside_afisha_host(): void
    {
        $this->postJson('/api/v1/venues/import-afisha', [
            'url' => 'https://example.com/event/1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_reports_pages_without_an_event_card(): void
    {
        Http::fake([
            'afisha.yandex.ru/*' => Http::response('<html><body><h1>Афиша Сургута</h1></body></html>', 200),
        ]);

        $this->postJson('/api/v1/venues/import-afisha', [
            'url' => 'https://afisha.yandex.ru/surgut',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AFISHA_PARSE_FAILED');
    }

    public function test_reports_unreachable_pages(): void
    {
        Http::fake([
            'afisha.yandex.ru/*' => Http::response('gateway timeout', 504),
        ]);

        $this->postJson('/api/v1/venues/import-afisha', [
            'url' => 'https://afisha.yandex.ru/surgut/concert/shura-2026-10-30',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AFISHA_UNREACHABLE');
    }
}
