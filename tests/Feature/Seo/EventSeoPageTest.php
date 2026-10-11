<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * SEO-поверхность страницы мероприятия: жизненный цикл, canonical и offers.
 *
 * ЗАЧЕМ ЭТОТ ТЕСТ. Все проверки ниже закрывают находки SEO-аудита
 * (SEO-AUDIT-2026-10-10.md), каждая из которых по-своему невидима:
 *
 *   P-01  архивация = мгновенный жёсткий 404. `archived` терминально, а страница
 *         с накопленным трафиком исчезала без редиректа — и восстановить её было
 *         нечем, потому что менеджер редиректов не существовал (P-02).
 *   P-02  таблица `redirects` и модель `Redirect` были без единого потребителя,
 *         а сама модель не совпадала со схемой (`source_path` вместо `source`).
 *   P-04  публичный `GET /api/v1/events` без параметров отдавал черновики.
 *   P-07  JSON-LD уезжал с `price: 0` — Google читал платное событие как
 *         бесплатное, ценовой сниппет не появлялся.
 *   P-14  `canonical_url` из БД принимался без проверки домена.
 *   P-15  сверялось только НАЛИЧИЕ сегмента publicId, а не его значение —
 *         `/event/slug/AAA` и `/event/slug/BBB` отдавали 200 с одним canonical.
 *
 * ПОЧЕМУ ЗДЕСЬ, А НЕ В `tests/Unit`. Проверяется живой HTTP-контур: web-роут,
 * глобальный middleware редиректов, Eloquent-связи `sessions.inventoryItems` и
 * `RefreshDatabase` против настоящей схемы. Бесплатформенный `tests/run.php`
 * пропускает Feature-тесты, поэтому этот файл исполняется `php vendor/bin/phpunit`.
 */
final class EventSeoPageTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── фикстуры ────────────────────────────────────────────────────────────

    /**
     * Мероприятие + сеанс + продаваемая позиция инвентаря.
     *
     * @return array{org: int, event: int, session: int, slug: string, publicId: string, inventory: int}
     */
    private function seedEvent(int $available = 3, int $priceAmount = 5_000_000): array
    {
        [$orgId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat(
            available: $available,
            priceAmount: $priceAmount,
        );

        $row = DB::table('events')->where('id', $eventId)->first();

        return [
            'org' => $orgId,
            'event' => $eventId,
            'session' => $sessionId,
            'slug' => (string) $row->slug,
            'publicId' => (string) $row->public_id,
            'inventory' => $inventoryId,
        ];
    }

    private function adminFor(int $organizationId): User
    {
        $now = now();

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
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

        DB::table('user_organization')->insert([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }

    private function canonicalFor(string $slug, string $publicId): string
    {
        return route('events.show', ['slug' => $slug, 'publicId' => $publicId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonLd(TestResponse $response): array
    {
        $html = (string) $response->getContent();

        $this->assertSame(
            1,
            preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches),
            'в HTML нет блока JSON-LD — разметка пропала целиком'
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    // ── P-15: publicId сверяется по значению ────────────────────────────────

    public function test_a_foreign_public_id_redirects_to_the_canonical_url(): void
    {
        $event = $this->seedEvent();

        $response = $this->get('/event/'.$event['slug'].'/WRONGIDENTIFIER0000000000');

        $response->assertStatus(301);
        $response->assertRedirect($this->canonicalFor($event['slug'], $event['publicId']));
    }

    public function test_the_short_url_redirects_to_the_canonical_url(): void
    {
        // Соседний случай: регрессия здесь означала бы, что сломали и работавший
        // редирект — проверка на «чужой publicId» не должна его задеть.
        $event = $this->seedEvent();

        $response = $this->get('/event/'.$event['slug']);

        $response->assertStatus(301);
        $response->assertRedirect($this->canonicalFor($event['slug'], $event['publicId']));
    }

    public function test_the_canonical_url_is_served_with_200(): void
    {
        $event = $this->seedEvent();

        $this->get('/event/'.$event['slug'].'/'.$event['publicId'])->assertStatus(200);
    }

    // ── P-01: архивация больше не равна удалению ────────────────────────────

    public function test_an_archived_event_stays_resolvable_and_is_noindexed(): void
    {
        $event = $this->seedEvent();

        DB::table('events')->where('id', $event['event'])->update(['status' => 'archived']);

        $response = $this->get('/event/'.$event['slug'].'/'.$event['publicId']);

        $response->assertStatus(200);

        $html = (string) $response->getContent();

        // `noindex` обязателен: страница живёт для людей и для накопленных
        // ссылок, но в индексе архиву делать нечего — в sitemap его тоже нет.
        $this->assertStringContainsString('name="robots"', $html, 'архивная страница не помечена noindex');
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_a_draft_event_is_still_not_found(): void
    {
        // Черновик публика не видела никогда — 404 остаётся. Проверка нужна
        // ровно потому, что предыдущий тест ослабил условие статуса.
        $event = $this->seedEvent();

        DB::table('events')->where('id', $event['event'])->update(['status' => 'draft']);

        $this->get('/event/'.$event['slug'].'/'.$event['publicId'])->assertStatus(404);
    }

    // ── P-14: canonical_url только со своего домена ─────────────────────────

    public function test_a_foreign_canonical_url_from_the_database_is_ignored(): void
    {
        $event = $this->seedEvent();

        DB::table('events')->where('id', $event['event'])->update([
            'canonical_url' => 'https://not-our-domain.example/event/hijacked',
        ]);

        $response = $this->get('/event/'.$event['slug'].'/'.$event['publicId']);
        $response->assertStatus(200);

        $html = (string) $response->getContent();

        $this->assertStringNotContainsString(
            'not-our-domain.example',
            $html,
            'чужой canonical уехал в HTML — страница будет снята с индексации как дубль'
        );
        $this->assertStringContainsString(
            $this->canonicalFor($event['slug'], $event['publicId']),
            $html
        );
    }

    // ── P-07: offers берутся из реального инвентаря ─────────────────────────

    public function test_offers_carry_the_real_minimum_price_and_currency(): void
    {
        // 5_000_000 минорных единиц = 50 000,00 ₽. Раньше в разметку уходил
        // `price: 0`, то есть «бесплатно».
        $event = $this->seedEvent(available: 3, priceAmount: 5_000_000);

        $jsonLd = $this->jsonLd($this->get('/event/'.$event['slug'].'/'.$event['publicId']));

        $this->assertArrayHasKey('offers', $jsonLd, 'блок offers пропал из разметки');
        $this->assertSame('50000.00', (string) $jsonLd['offers']['price']);
        $this->assertSame('RUB', $jsonLd['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $jsonLd['offers']['availability']);
    }

    public function test_offers_report_sold_out_when_nothing_is_available(): void
    {
        $event = $this->seedEvent(available: 0);

        $jsonLd = $this->jsonLd($this->get('/event/'.$event['slug'].'/'.$event['publicId']));

        $this->assertSame(
            'https://schema.org/SoldOut',
            $jsonLd['offers']['availability'],
            'распроданное событие объявлено доступным — разметка обещает билеты, которых нет'
        );
    }

    // ── P-04: публичный каталог не отдаёт неопубликованное ──────────────────

    public function test_an_anonymous_catalog_request_never_returns_drafts(): void
    {
        $event = $this->seedEvent();

        DB::table('events')->where('id', $event['event'])->update(['status' => 'draft']);

        $response = $this->getJson('/api/v1/events');
        $response->assertStatus(200);

        $statuses = array_column($response->json('data'), 'status');

        $this->assertNotContains('draft', $statuses, 'публичный каталог вернул черновик');
        $this->assertSame([], $statuses, 'каталог должен был вернуть пустой список: опубликованных событий нет');
    }

    public function test_an_explicit_status_parameter_from_an_anonymous_client_is_ignored(): void
    {
        // Обходной путь, который легко не заметить: `?status=draft` не должен
        // открывать черновики тому, кто просто угадал параметр.
        $event = $this->seedEvent();

        DB::table('events')->where('id', $event['event'])->update(['status' => 'draft']);

        $response = $this->getJson('/api/v1/events?status=draft');
        $response->assertStatus(200);

        $this->assertSame([], array_column($response->json('data'), 'status'));
    }

    public function test_a_staff_member_can_still_filter_the_catalog_by_draft(): void
    {
        // Админка ходит с токеном и запрашивает черновики явно. Ограничение
        // обязано включаться только для анонимов и покупателей.
        $event = $this->seedEvent();

        DB::table('events')->where('id', $event['event'])->update(['status' => 'draft']);

        $this->adminFor($event['org']);

        $response = $this->getJson('/api/v1/events?status=draft');
        $response->assertStatus(200);

        $this->assertContains('draft', array_column($response->json('data'), 'status'));
    }

    // ── P-13: без сборки витрины — 503, а не пустой 200 ─────────────────────

    public function test_a_missing_storefront_build_yields_503_not_an_empty_200(): void
    {
        // Раньше здесь отдавался 200 с пустым `<div id="app">` и шаблонным
        // title — soft 404 под живым адресом из sitemap. Главная в этом
        // состоянии давно отдаёт честный 503; страница события не отдавала.
        $event = $this->seedEvent();

        config(['nabilet.storefront.shell_path' => base_path('dist/__missing__.html')]);

        $response = $this->get('/event/'.$event['slug'].'/'.$event['publicId']);

        $response->assertStatus(503);
        $this->assertSame('STORE_FRONT_NOT_BUILT', $response->json('error.code'));
    }

    public function test_a_draft_is_404_even_when_the_storefront_is_missing(): void
    {
        // Порядок проверок: «такого события нет» информативнее, чем «витрина не
        // собрана», поэтому 404 не должен превращаться в 503.
        $event = $this->seedEvent();

        DB::table('events')->where('id', $event['event'])->update(['status' => 'draft']);

        config(['nabilet.storefront.shell_path' => base_path('dist/__missing__.html')]);

        $this->get('/event/'.$event['slug'].'/'.$event['publicId'])->assertStatus(404);
    }

    // ── P-02: менеджер редиректов ───────────────────────────────────────────

    public function test_renaming_a_slug_records_a_permanent_redirect(): void
    {
        $event = $this->seedEvent();
        $oldSlug = $event['slug'];
        $newSlug = 'renamed-'.Str::random(6);

        $this->adminFor($event['org']);

        $this->patchJson('/api/v1/events/'.$event['event'], ['slug' => $newSlug])
            ->assertStatus(200);

        $row = DB::table('redirects')->where('source', '/event/'.$oldSlug)->first();

        $this->assertNotNull($row, 'старый адрес не сохранён — переименование теряет страницу');
        $this->assertSame(301, (int) $row->status_code);
        $this->assertSame(1, (int) $row->active);
        $this->assertSame('/event/'.$newSlug.'/'.$event['publicId'], (string) $row->destination);
    }

    public function test_an_unchanged_slug_records_no_redirect(): void
    {
        // Обратная сторона: правка карточки без смены адреса не должна плодить
        // записи, иначе таблица засорится самопереходами.
        $event = $this->seedEvent();

        $this->adminFor($event['org']);

        $this->patchJson('/api/v1/events/'.$event['event'], ['title' => 'Новое название'])
            ->assertStatus(200);

        $this->assertSame(0, DB::table('redirects')->count());
    }

    public function test_a_recorded_redirect_is_served_before_the_router_returns_404(): void
    {
        // Ядро P-02: путь, для которого маршрута НЕТ. Именно поэтому middleware
        // стоит в глобальном стеке — групповое `web` до несопоставленного пути
        // не доходит вовсе.
        DB::table('redirects')->insert([
            'source' => '/event/staraya-afisha',
            'destination' => '/event/novaya-afisha/evt_123',
            'status_code' => 301,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/event/staraya-afisha');

        $response->assertStatus(301);
        $response->assertRedirect('/event/novaya-afisha/evt_123');
    }

    public function test_an_inactive_redirect_is_not_served(): void
    {
        DB::table('redirects')->insert([
            'source' => '/event/otklyuchennyj',
            'destination' => '/event/novaya-afisha/evt_123',
            'status_code' => 301,
            'active' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/event/otklyuchennyj')->assertStatus(404);
    }

    public function test_a_self_redirect_is_ignored_instead_of_looping(): void
    {
        // Запись с назначением, равным источнику, отправила бы браузер в
        // ERR_TOO_MANY_REDIRECTS. Лучше отдать страницу как обычно.
        DB::table('redirects')->insert([
            'source' => '/event/petlya',
            'destination' => '/event/petlya',
            'status_code' => 301,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/event/petlya');

        $this->assertNotSame(301, $response->getStatusCode(), 'самопереход ушёл в цикл');
    }

    public function test_redirects_do_not_touch_the_api_surface(): void
    {
        // У API свой контракт (§66 envelope) и своя версия в пути: редирект в
        // ответ на API-вызов — это сломанный клиент, а не SEO.
        DB::table('redirects')->insert([
            'source' => '/api/v1/events',
            'destination' => '/event/novaya-afisha',
            'status_code' => 301,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/events');

        $this->assertNotSame(301, $response->getStatusCode());
        $response->assertStatus(200);
    }

    public function test_the_health_endpoint_is_never_intercepted(): void
    {
        // `/up` обязан отвечать даже при недоступной БД: запрос в `redirects` из
        // глобального middleware превратил бы «база лежит» в 500 вместо честного
        // ответа о состоянии. Запись здесь — самая злая из возможных.
        DB::table('redirects')->insert([
            'source' => '/up',
            'destination' => '/event/novaya-afisha',
            'status_code' => 301,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotSame(301, $this->get('/up')->getStatusCode());
    }

    public function test_a_redirect_is_never_applied_to_a_write_request(): void
    {
        // Редирект адреса не должен менять семантику POST: клиент, отправлявший
        // форму, молча потерял бы тело запроса.
        DB::table('redirects')->insert([
            'source' => '/api/v1/events',
            'destination' => '/event/novaya-afisha',
            'status_code' => 301,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/events', []);

        $this->assertNotSame(301, $response->getStatusCode());
    }
}
