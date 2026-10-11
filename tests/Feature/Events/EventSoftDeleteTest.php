<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Удаление мероприятия — мягкое, с ответом 410 (SEO-аудит P-24).
 *
 * ДЕФЕКТ. `DELETE /api/v1/events/{id}` выполнял физический `DELETE`, и это
 * имело три следствия, каждое из которых заметно только на живых данных:
 *
 *   1. `ON DELETE CASCADE` уносил вместе с событием сеансы, переводы, промокоды,
 *      пакеты офлайн-продаж, даты, спикеров, спонсоров, вопросы, артистов и
 *      программу — историю нельзя было восстановить даже теоретически.
 *   2. `fk_tickets_event` объявлен `ON DELETE RESTRICT`: у события с проданными
 *      билетами удаление падало ошибкой MySQL 1451 → 500. То есть «удалить»
 *      работало ровно до первой продажи.
 *   3. Публичный адрес исчезал мгновенно, `slug` освобождался, и его мог занять
 *      другой концерт — тот же URL начинал означать другое событие, молча.
 *
 * ЧТО ПРОВЕРЯЕТСЯ. Не «строка осталась», а все три следствия по отдельности:
 * строка на месте, каскад не сработал, slug занят, страница отвечает 410, а
 * несуществующий адрес по-прежнему 404.
 */
final class EventSoftDeleteTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── Удаление ───────────────────────────────────────────────────────────

    /** Строка помечается удалённой, но не исчезает. */
    public function test_deleting_an_event_keeps_the_row(): void
    {
        $event = $this->seedEvent();

        $this->actingAsAdmin($event['org']);
        $this->deleteJson('/api/v1/events/' . $event['event'])->assertStatus(204);

        $row = DB::table('events')->where('id', $event['event'])->first();

        $this->assertNotNull($row, 'строка мероприятия исчезла из базы');
        $this->assertNotNull($row->deleted_at, 'deleted_at не заполнен — это не мягкое удаление');
    }

    /**
     * Сеансы переживают удаление события.
     *
     * Это проверка того, что каскад не сработал: `fk_sessions_event` объявлен
     * `ON DELETE CASCADE`, поэтому физическое удаление уносило сеансы, а с ними
     * — инвентарь, на который ссылаются заказы.
     */
    public function test_sessions_survive_the_deletion(): void
    {
        $event = $this->seedEvent();

        $sessionsBefore = DB::table('sessions')->where('event_id', $event['event'])->count();
        $this->assertGreaterThan(0, $sessionsBefore, 'фикстура не создала сеанс');

        $this->actingAsAdmin($event['org']);
        $this->deleteJson('/api/v1/events/' . $event['event'])->assertStatus(204);

        $this->assertSame(
            $sessionsBefore,
            DB::table('sessions')->where('event_id', $event['event'])->count(),
            'сеансы унесло каскадом — история заказов потеряна'
        );
    }

    /**
     * Slug остаётся занятым.
     *
     * Смысл: адрес `/event/{slug}` не должен менять значение. Если бы удаление
     * освобождало slug, новый концерт занял бы проиндексированный URL, и
     * поисковик показывал бы по нему другое событие — без единого сигнала об
     * ошибке.
     */
    public function test_the_slug_stays_reserved_after_deletion(): void
    {
        $event = $this->seedEvent();

        $this->actingAsAdmin($event['org']);
        $this->deleteJson('/api/v1/events/' . $event['event'])->assertStatus(204);

        $response = $this->postJson('/api/v1/events', [
            'organization_id' => $event['org'],
            'title' => 'Другой концерт по тому же адресу',
            'slug' => $event['slug'],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey(
            'slug',
            (array) $response->json('error.details.fields'),
            'удалённое событие освободило свой публичный адрес'
        );
    }

    // ── 410 вместо 404 ─────────────────────────────────────────────────────

    /**
     * Страница удалённого мероприятия отвечает 410 Gone.
     *
     * Не 404: 404 читается как «проверьте ссылку» и требует повторных обходов,
     * 410 означает «была и не вернётся» — по нему поисковик снимает URL из
     * индекса сразу.
     *
     * Тело — брендированный HTML (аудит P-16), а не конверт §66: `/event/…` это
     * адрес, который человек открывает из выдачи или из письма. Конверт остался
     * у JSON-API, где он и уместен.
     */
    public function test_the_page_of_a_deleted_event_answers_gone(): void
    {
        $event = $this->seedEvent();

        $this->actingAsAdmin($event['org']);
        $this->deleteJson('/api/v1/events/' . $event['event'])->assertStatus(204);

        $response = $this->get(route('events.show', [
            'slug' => $event['slug'],
            'publicId' => $event['publicId'],
        ]));

        $response->assertStatus(410);
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Мероприятие удалено', (string) $response->getContent());
        $this->assertStringContainsString('<meta name="robots" content="noindex, follow"', (string) $response->getContent());
    }

    /** Витринный API тоже отвечает 410 — витрина покажет «снято с продажи». */
    public function test_the_storefront_api_answers_gone(): void
    {
        $event = $this->seedEvent();

        $this->actingAsAdmin($event['org']);
        $this->deleteJson('/api/v1/events/' . $event['event'])->assertStatus(204);

        $this->getJson('/api/v1/events/by-slug/' . $event['slug'])
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'EVENT_GONE');
    }

    /**
     * Несуществующий адрес остаётся 404.
     *
     * Без этого теста предыдущий проходил бы и в реализации, отвечающей 410 на
     * ЛЮБОЙ неизвестный slug, — то есть карта сайта сообщала бы поисковику, что
     * каждый опечатанный адрес когда-то существовал.
     */
    public function test_an_unknown_slug_is_still_not_found(): void
    {
        $this->getJson('/api/v1/events/by-slug/takogo-ne-bylo-' . Str::random(6))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'EVENT_NOT_FOUND');
    }

    /** Черновик остаётся 404, а не 410: публика его никогда не видела. */
    public function test_a_draft_is_not_found_rather_than_gone(): void
    {
        $event = $this->seedEvent(EventStateMachine::DRAFT);

        $this->get(route('events.show', [
            'slug' => $event['slug'],
            'publicId' => $event['publicId'],
        ]))->assertStatus(404);
    }

    // ── Удалённое не попадает в выдачу ─────────────────────────────────────

    /**
     * Удалённое мероприятие исчезает из карты сайта.
     *
     * Глобальный scope трейта `SoftDeletes` применяется к запросам Eloquent, а
     * `SitemapService` строит карту именно ими. Отдельный тест нужен потому,
     * что карта кешируется на час: если бы кеш пережил удаление, поисковик
     * полчаса получал бы ссылку на 410.
     */
    public function test_a_deleted_event_leaves_the_sitemap(): void
    {
        $event = $this->seedEvent();

        $this->assertStringContainsString($event['slug'], $this->eventsSitemap());

        $this->actingAsAdmin($event['org']);
        $this->deleteJson('/api/v1/events/' . $event['event'])->assertStatus(204);

        // Кеш карты (аудит P-10) сбрасывается намеренно: проверяется не он, а
        // выборка. В проде карта обновится при следующем промахе кеша.
        Cache::flush();

        $this->assertStringNotContainsString($event['slug'], $this->eventsSitemap());
    }

    /** Удалённое мероприятие исчезает из публичного каталога. */
    public function test_a_deleted_event_leaves_the_public_catalog(): void
    {
        $event = $this->seedEvent();

        $this->getJson('/api/v1/events')
            ->assertStatus(200)
            ->assertJsonFragment(['slug' => $event['slug']]);

        $this->actingAsAdmin($event['org']);
        $this->deleteJson('/api/v1/events/' . $event['event'])->assertStatus(204);

        $slugs = array_column((array) $this->getJson('/api/v1/events')->json('data'), 'slug');

        $this->assertNotContains($event['slug'], $slugs, 'удалённое событие осталось в каталоге');
    }

    // ── Фикстуры ───────────────────────────────────────────────────────────

    /**
     * @return array{org: int, event: int, session: int, slug: string, publicId: string, inventory: int}
     */
    private function seedEvent(string $status = EventStateMachine::PUBLISHED): array
    {
        [$orgId, $eventId, $sessionId, $inventoryId] = $this->seedSellableSeat(3);

        DB::table('events')->where('id', $eventId)->update([
            'status' => $status,
            'published_at' => now()->subMonth(),
        ]);

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

    private function eventsSitemap(): string
    {
        $response = $this->get('/api/v1/sitemap-events.xml');

        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    private function actingAsAdmin(int $organizationId): User
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
}
