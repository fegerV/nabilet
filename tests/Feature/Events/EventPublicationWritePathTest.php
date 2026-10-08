<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Events\Http\Requests\StoreEventRequest;
use Nabilet\Modules\Events\Http\Requests\UpdateEventRequest;
use Nabilet\Tests\Support\SellableSeatFixtures;
use Tests\TestCase;

/**
 * Публикация мероприятия против реальной схемы: единственный путь к `published`.
 *
 * ЗАЧЕМ ЭТОТ ТЕСТ. `UpdateEventRequest::stripStatusField()` вырезал `status` на
 * обновлении, и вокруг этого уже была написана история: публикация через тело
 * `PUT` не запускала `EventPublicationPolicy`, поэтому событие БЕЗ СЕАНСОВ
 * попадало на витрину и в sitemap. Дыру закрыли на обновлении — и не заметили,
 * что на СОЗДАНИИ её не было никогда:
 *
 *   POST /api/v1/events  {"title": "…", "slug": "…", "status": "published"}
 *
 * проходил правило `nullable|in:draft,published,archived,cancelled`, а
 * `EventService::create()` проверял только `EventStatus::isValid()`. Политика не
 * вызывалась ни разу, `published_at` не проставлялся — то есть получалась ровно
 * та строка, которую `auditDecision()` помечает как `PUBLISHED_WITHOUT_A_MOMENT`:
 * «на сайте, без ответа на вопрос с каких пор». Сеансов у свежесозданного
 * события тоже нет, так что это ещё и `NO_SESSIONS` — «тонкая страница» в
 * sitemap с первого дня.
 *
 * ПОЧЕМУ ЗДЕСЬ, А НЕ В `tests/Unit`. Проверка идёт через HTTP: нужны
 * `Illuminate\Foundation\Http\FormRequest`, живая схема и `auth:api`.
 * Бесплатформенный `tests/run.php` не подгружает Illuminate и падал на
 * `Class "Illuminate\Foundation\Http\FormRequest" not found` — то есть тест
 * прошёл бы «зелёным» по причине, не имеющей отношения к предмету. Этот файл
 * исполняется `php vendor/bin/phpunit`.
 *
 * ЧТО ИМЕННО ДОКАЗЫВАЕТСЯ (не «код ответа из списка допустимых»):
 *   1. `status: published` в теле создания не доходит до `INSERT`;
 *   2. публикация без сеансов отказывает 409 и код `NO_SESSIONS`;
 *   3. публикация с сеансом проставляет и `status`, и `published_at`;
 *   4. повторная публикация — `no_change` и НЕ двигает `updated_at`
 *      (`SitemapService` использует его как `lastmod`).
 */
final class EventPublicationWritePathTest extends TestCase
{
    use RefreshDatabase;
    use SellableSeatFixtures;

    // ── фикстуры ────────────────────────────────────────────────────────────

    private function seedOrganization(): int
    {
        $now = now()->toDateTimeString();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Администратор организации — то, что требует middleware `admin`
     * (`StaffRole::isStaff()` читает `user_roles`, а не колонку в `users`).
     */
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

        // `roles.slug` уникален — берём существующую роль, если тест уже её создал.
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

    /**
     * Создать мероприятие через API и вернуть его id.
     *
     * @param array<string, mixed> $extra
     */
    private function createEvent(int $organizationId, array $extra = []): int
    {
        $response = $this->postJson('/api/v1/events', array_merge([
            'organization_id' => $organizationId,
            'title' => 'Концерт «Секрет»',
            'slug' => 'koncert-sekret-' . Str::random(5),
        ], $extra));

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    /**
     * Привязать сеанс к мероприятию.
     *
     * Фикстура строит обязательную цепочку внешних ключей
     * (venue → hall → hall_schema_versions → sessions), потому что собрать
     * сеанс в обход неё нельзя: все звенья NOT NULL. Затем сеанс
     * перенаправляется на проверяемое мероприятие — политика публикации читает
     * из сеанса ровно один факт, `sessions.event_id`.
     */
    private function attachSessionTo(int $eventId): int
    {
        [, , $sessionId] = $this->seedSellableSeat(available: 3);

        DB::table('sessions')->where('id', $sessionId)->update(['event_id' => $eventId]);

        return $sessionId;
    }

    // ── 1. создание не может опубликовать ───────────────────────────────────

    public function test_neither_write_request_declares_a_status_rule(): void
    {
        // Механизм, который здесь всё держит. `Validator::validated()` собирает
        // результат, перебирая `getRules()`, и читает значения из СНИМКА данных,
        // сделанного при создании валидатора. Поэтому `stripStatusField()`,
        // удаляющий ключ из входного набора, на `validated()` не влияет вообще:
        // ключ исчезает из результата только тогда, когда для него нет правила.
        //
        // Это и есть та строка, которую легко «починить» обратно: вернуть
        // правило `in:draft,published,...` на создание и понадеяться на
        // вырезание. Тогда обход политики откроется снова, а все остальные
        // тесты здесь останутся зелёными, потому что они шлют `status`, который
        // будет отброшен... но не раньше, чем попадёт в `validated()`.
        $storeRules = StoreEventRequest::create('/api/v1/events', 'POST', [])->rules();
        $updateRules = UpdateEventRequest::create('/api/v1/events/1', 'PATCH', [])->rules();

        $this->assertArrayNotHasKey('status', $storeRules, 'правило status на создании снова открывает обход политики');
        $this->assertArrayNotHasKey('status', $updateRules);
    }

    public function test_creating_an_event_with_status_published_still_writes_a_draft(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsAdmin($organizationId);

        // Тело, которым обходили политику. Раньше оно давало опубликованное
        // событие без сеансов и без `published_at`.
        $eventId = $this->createEvent($organizationId, ['status' => 'published']);

        $row = DB::table('events')->where('id', $eventId)->first();

        $this->assertNotNull($row, 'строка events не записалась');
        $this->assertSame(
            'draft',
            (string) $row->status,
            'status из тела создания дошёл до INSERT: публикация в обход EventPublicationPolicy'
        );
        $this->assertNull($row->published_at, 'published_at не должен появляться у черновика');
    }

    public function test_creating_an_event_without_a_status_still_writes_a_draft(): void
    {
        // Базовый случай рядом с предыдущим: проверка «published не проходит»
        // бессмысленна, если обычное создание вообще не работает.
        $organizationId = $this->seedOrganization();
        $this->actingAsAdmin($organizationId);

        $eventId = $this->createEvent($organizationId);
        $row = DB::table('events')->where('id', $eventId)->first();

        $this->assertSame('draft', (string) $row->status);
        $this->assertNull($row->published_at);
    }

    // ── 2. публикация без сеансов отказывает ────────────────────────────────

    public function test_publishing_an_event_without_sessions_is_refused_with_409(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsAdmin($organizationId);

        $eventId = $this->createEvent($organizationId);

        $response = $this->postJson("/api/v1/events/{$eventId}/publish");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NO_SESSIONS');

        // Отказ не должен быть «наполовину применённым».
        $row = DB::table('events')->where('id', $eventId)->first();
        $this->assertSame('draft', (string) $row->status, 'отказ политики всё равно изменил статус');
        $this->assertNull($row->published_at);
    }

    public function test_the_publish_button_is_the_only_way_and_the_field_in_the_body_is_gone(): void
    {
        // Прямая проверка требования: раньше публикация была ТОЛЬКО полем в теле
        // запроса. Теперь поле игнорируется, а переход состояния делает
        // отдельный эндпоинт — и он же отвечает за проверки.
        $organizationId = $this->seedOrganization();
        $this->actingAsAdmin($organizationId);

        $eventId = $this->createEvent($organizationId, ['status' => 'published']);
        $this->attachSessionTo($eventId);

        // Сеанс есть, но статус всё ещё draft: тело запроса на него не повлияло.
        $this->assertSame('draft', (string) DB::table('events')->where('id', $eventId)->value('status'));

        $this->postJson("/api/v1/events/{$eventId}/publish")->assertStatus(200);

        $this->assertSame('published', (string) DB::table('events')->where('id', $eventId)->value('status'));
    }

    // ── 3. публикация с сеансом работает ────────────────────────────────────

    public function test_publishing_an_event_with_a_session_sets_the_status_and_the_moment(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsAdmin($organizationId);

        $eventId = $this->createEvent($organizationId);
        $this->attachSessionTo($eventId);

        $response = $this->postJson("/api/v1/events/{$eventId}/publish");

        $response->assertStatus(200);
        $response->assertJsonPath('meta.verdict', 'allowed');
        $response->assertJsonPath('meta.changed', true);
        $response->assertJsonPath('data.status', 'published');

        $row = DB::table('events')->where('id', $eventId)->first();
        $this->assertSame('published', (string) $row->status);
        $this->assertNotNull($row->published_at, 'published_at обязателен: иначе строка = PUBLISHED_WITHOUT_A_MOMENT');
    }

    // ── 4. повторная публикация — no_change ─────────────────────────────────

    public function test_republishing_does_not_bump_updated_at(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsAdmin($organizationId);

        $eventId = $this->createEvent($organizationId);
        $this->attachSessionTo($eventId);

        $this->postJson("/api/v1/events/{$eventId}/publish")->assertStatus(200);
        $firstUpdatedAt = DB::table('events')->where('id', $eventId)->value('updated_at');
        $firstPublishedAt = DB::table('events')->where('id', $eventId)->value('published_at');

        // Пауза, чтобы UPDATE был бы виден по updated_at, если бы он случился.
        usleep(1_100_000);

        $second = $this->postJson("/api/v1/events/{$eventId}/publish");

        $second->assertStatus(200);
        $second->assertJsonPath('meta.verdict', 'no_change');
        $second->assertJsonPath('meta.changed', false);

        $this->assertSame(
            $firstUpdatedAt,
            DB::table('events')->where('id', $eventId)->value('updated_at'),
            'повторная публикация подняла updated_at: sitemap объявит страницу изменённой без причины'
        );
        $this->assertSame(
            $firstPublishedAt,
            DB::table('events')->where('id', $eventId)->value('published_at'),
            'published_at переписан при повторной публикации'
        );
    }

    // ── 5. отмена снимает опубликованное событие ────────────────────────────

    public function test_an_event_with_nothing_sold_can_be_cancelled(): void
    {
        $organizationId = $this->seedOrganization();
        $this->actingAsAdmin($organizationId);

        $eventId = $this->createEvent($organizationId);
        $this->attachSessionTo($eventId);
        $this->postJson("/api/v1/events/{$eventId}/publish")->assertStatus(200);

        $response = $this->postJson("/api/v1/events/{$eventId}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('meta.verdict', 'allowed');

        $this->assertSame('cancelled', (string) DB::table('events')->where('id', $eventId)->value('status'));
    }

    // ── 6. анонимный доступ к смене состояния закрыт ────────────────────────

    public function test_an_anonymous_request_cannot_publish(): void
    {
        // Мероприятие и сеанс кладём прямо в схему, без HTTP и без аутентификации:
        // цель теста — что анонимный запрос не меняет состояние, и настройка
        // админа здесь только мешала бы (guard остаётся разрешённым на весь тест).
        $organizationId = $this->seedOrganization();

        $now = now()->toDateTimeString();
        $eventId = (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => 'Концерт «Секрет»',
            'slug' => 'koncert-sekret-' . Str::random(5),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->attachSessionTo($eventId);

        $this->postJson("/api/v1/events/{$eventId}/publish")->assertStatus(401);

        $row = DB::table('events')->where('id', $eventId)->first();
        $this->assertSame('draft', (string) $row->status, 'анонимный запрос изменил статус мероприятия');
        $this->assertNull($row->published_at);
    }
}
