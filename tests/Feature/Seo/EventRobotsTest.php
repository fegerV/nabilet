<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Events\Domain\EventRobots;
use Nabilet\Modules\Events\StateMachines\EventStateMachine;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Управление индексацией через `events.robots` (SEO-аудит P-23).
 *
 * ДЕФЕКТ БЫЛ ДВОЙНОЙ, и вторая половина заметнее первой.
 *
 *   1. Поле `robots` читалось рендером страницы (`EventPageController`), но
 *      записать его через API было НЕЧЕМ: правила для него не существовало ни в
 *      `StoreEventRequest`, ни в `UpdateEventRequest`, а `validated()` собирает
 *      результат по списку правил. То есть «управление индексацией» было
 *      заявлено и недоступно.
 *   2. Значение уходило в `<meta name="robots">` КАК ЕСТЬ — свободный
 *      `VARCHAR(255)` без проверки. Как только поле станет управляемым, опечатка
 *      (`noindexs`) или фраза целиком уезжает в мета-тег, робот разбирает её
 *      по-своему, и заметить это можно только в Search Console — уже после
 *      выпадения страниц из индекса.
 *
 * Отдельно и важнее обоих: поле МОГЛО ОТКЛЮЧИТЬ ЗАЩИТУ. Строка
 * `$event->robots ?: $this->defaultRobots($event)` означает «поле побеждает
 * автоматику», поэтому `index, follow`, выставленный у отменённого события,
 * открывал его для индексации — тогда как в sitemap такого события нет, а
 * сниппет обещал бы покупку, которой не будет. Теперь запрет по статусу —
 * неотменяемый минимум, а поле к нему добавляется.
 */
final class EventRobotsTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── Разбор значения (чистая логика) ────────────────────────────────────

    /**
     * Мусорный токен отбрасывается, а не уезжает в мета-тег.
     *
     * Именно из-за этого класс и существует: значение приходит от человека.
     */
    public function test_unknown_directives_are_dropped(): void
    {
        $this->assertSame(['noindex', 'nofollow'], EventRobots::parse('noindex, nofollow'));
        $this->assertSame(['noindex'], EventRobots::parse('noindex, noindexs'));
        $this->assertSame([], EventRobots::parse('не индексировать эту страницу'));
        $this->assertSame(['noindex'], EventRobots::parse('NoIndex'));
    }

    /** Проверка валидности: пусто — допустимо, мусор — нет. */
    public function test_validity_rejects_unknown_tokens_only(): void
    {
        $this->assertTrue(EventRobots::isValid(''));
        $this->assertTrue(EventRobots::isValid('noindex, noarchive'));
        $this->assertTrue(EventRobots::isValid('NOINDEX,NOSNIPPET'));

        $this->assertFalse(EventRobots::isValid('noindexs'));
        $this->assertFalse(EventRobots::isValid('не индексировать'));
        // Директивы с параметрами не поддерживаются осознанно: принять строку и
        // не проверить значение — это ровно та полумера, из-за которой поле и
        // было опасно.
        $this->assertFalse(EventRobots::isValid('max-snippet:160'));
    }

    /**
     * `index` и `noindex` в одном теге схлопываются в запрещающую директиву.
     *
     * Спецификация предписывает роботу выбрать более ограничительное значение,
     * но разные роботы делают это по-разному, а смысл поля — чтобы запрет был
     * исполнен, а не истолкован.
     */
    public function test_contradictory_directives_collapse_to_the_restrictive_one(): void
    {
        $this->assertSame('noindex', EventRobots::normalize(['index', 'noindex']));
        $this->assertSame('nofollow', EventRobots::normalize(['follow', 'nofollow']));
        $this->assertNull(EventRobots::normalize([]));
    }

    // ── Поле записывается и доезжает до страницы ───────────────────────────

    /** Валидное значение сохраняется и попадает в мета-тег. */
    public function test_a_valid_value_is_stored_and_rendered(): void
    {
        $event = $this->seedEvent();

        $this->actingAsAdmin($event['org']);

        $this->putJson('/api/v1/events/' . $event['event'], ['robots' => 'noindex, nosnippet'])
            ->assertStatus(200)
            ->assertJsonPath('data.robots', 'noindex, nosnippet');

        $this->assertSame('noindex, nosnippet', $this->robotsOf($event['event']));
        $this->assertStringContainsString(
            '<meta name="robots" content="noindex, nosnippet" />',
            $this->page($event)
        );
    }

    /**
     * Мусор не сохраняется: 422 с указанием поля.
     *
     * Конверт §66, а не формат Laravel — см. `assertRejected()`.
     */
    public function test_garbage_is_rejected_before_it_reaches_the_database(): void
    {
        $event = $this->seedEvent();

        $this->actingAsAdmin($event['org']);

        $this->assertRejected(
            $this->putJson('/api/v1/events/' . $event['event'], ['robots' => 'не индексировать'])
        );

        $this->assertRejected(
            $this->putJson('/api/v1/events/' . $event['event'], ['robots' => 'noindexs, follow'])
        );

        $this->assertNull($this->robotsOf($event['event']), 'мусор всё-таки записался');
    }

    /** Поле можно выставить и при создании — правило есть в обоих запросах. */
    public function test_the_field_can_be_set_on_create(): void
    {
        $orgId = $this->seedOrganization();
        $this->actingAsAdmin($orgId);

        $response = $this->postJson('/api/v1/events', [
            'organization_id' => $orgId,
            'title' => 'Концерт',
            'slug' => 'robots-' . Str::random(6),
            'robots' => 'noarchive',
        ]);

        $response->assertStatus(201);
        $this->assertSame('noarchive', (string) $response->json('data.robots'));
    }

    // ── Запрет по статусу неотменяем ───────────────────────────────────────

    /**
     * `index, follow` у отменённого события НЕ открывает его для индексации.
     *
     * Это главная содержательная проверка. Раньше поле побеждало автоматику
     * (`?:`), и отменённое событие — которое в sitemap отсутствует и чей сниппет
     * обещал бы покупку — становилось индексируемым по одному значению в
     * админке.
     */
    public function test_the_status_ban_cannot_be_overridden_by_the_field(): void
    {
        $event = $this->seedEvent(EventStateMachine::CANCELLED);

        DB::table('events')->where('id', $event['event'])->update(['robots' => 'index, follow']);

        $html = $this->page($event);

        $this->assertStringContainsString('<meta name="robots" content="noindex', $html);
        $this->assertStringNotContainsString('content="index, follow"', $html);
    }

    /**
     * У опубликованного события поле работает как есть: автоматика молчит.
     *
     * Без этого теста предыдущий проходил бы и в случае, если поле перестали
     * учитывать вообще.
     */
    public function test_the_field_is_honoured_for_an_indexable_event(): void
    {
        $event = $this->seedEvent(EventStateMachine::PUBLISHED);

        DB::table('events')->where('id', $event['event'])->update(['robots' => 'noindex']);

        $this->assertStringContainsString(
            '<meta name="robots" content="noindex" />',
            $this->page($event)
        );
    }

    /**
     * Без поля у опубликованного события мета-тега нет вовсе.
     *
     * Пустая строка вместо отсутствия тега — это `<meta name="robots" content="">`,
     * что валидаторы считают ошибкой, а робот может истолковать как угодно.
     */
    public function test_an_indexable_event_without_the_field_has_no_robots_meta(): void
    {
        $event = $this->seedEvent(EventStateMachine::PUBLISHED);

        $this->assertStringNotContainsString('<meta name="robots"', $this->page($event));
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

    /** @param array{slug: string, publicId: string} $event */
    private function page(array $event): string
    {
        $response = $this->get(route('events.show', [
            'slug' => $event['slug'],
            'publicId' => $event['publicId'],
        ]));

        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    private function robotsOf(int $eventId): ?string
    {
        $value = DB::table('events')->where('id', $eventId)->value('robots');

        return $value === null ? null : (string) $value;
    }

    /**
     * 422 с ошибкой именно по полю `robots`.
     *
     * Конверт §66: `{"error":{"code":"VALIDATION_ERROR","details":{"fields":{…}}}}`.
     * `assertJsonValidationErrors()` ищет ключ `errors`, которого в этом
     * контракте нет.
     */
    private function assertRejected(TestResponse $response): void
    {
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertArrayHasKey(
            'robots',
            (array) $response->json('error.details.fields'),
            'конверт §66 не сообщил, что конфликтует именно поле robots'
        );
    }

    private function seedOrganization(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт ' . Str::random(4),
            'slug' => 'surgut-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
