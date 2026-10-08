<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Media\Models\MediaAsset;
use Nabilet\Modules\Media\Models\MediaLink;
use Tests\TestCase;

/**
 * Галерея мероприятия — «дополнительные фото и видео».
 *
 * ЭТО НЕ УКРАШЕНИЕ, А ЗАКРЫТИЕ ДЫРЫ
 *
 * В форме мероприятия раздела с дополнительными фото не существовало вообще, а
 * `media_links` (таблица, для этого и предназначенная) стояла пустой: модуль
 * Media не отдавал ни одного маршрута. Тесты проверяют сквозной путь
 * «файл → привязка к мероприятию → список галереи», а не работу сервиса в
 * изоляции: сервис мог быть безупречен, пока HTTP-слой отсутствовал.
 *
 * ГЛАВНОЕ, ЧТО ЗДЕСЬ ЗАЩИЩЕНО
 *
 *  * Один файл может быть привязан к мероприятию ДВАЖДЫ в разных ролях (афиша и
 *    галерея) — это две строки, а не конфликт уникального ключа.
 *  * Удаление из галереи снимает ТОЛЬКО роль `gallery`: иначе мероприятие
 *    осталось бы без афиши, которую администратор не трогал.
 *  * Чужое мероприятие даёт 404, а не 403.
 */
class EventGalleryTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->organizationId = $this->seedOrganization();
    }

    public function test_the_gallery_requires_authentication(): void
    {
        $eventId = $this->seedEvent($this->organizationId);

        $this->getJson('/api/v1/events/' . $eventId . '/gallery')->assertStatus(401);
    }

    /**
     * Сквозной путь: один запрос загружает файл И привязывает его к мероприятию.
     *
     * Именно так работает форма: администратор выбрал файлы, отправил их, и они
     * появились в галерее. Два запроса (загрузить, потом привязать) оставляли бы
     * мусор в хранилище, если второй не прошёл.
     */
    public function test_a_file_uploaded_with_an_entity_is_attached_in_the_same_request(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);

        $response = $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('gallery-1.jpg', 1600, 900),
            'entity_type' => 'event',
            'entity_id' => $eventId,
            'role' => 'gallery',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);

        $asset = MediaAsset::query()->firstOrFail();

        $this->assertDatabaseHas('media_links', [
            'media_asset_id' => $asset->id,
            'entity_type' => 'event',
            'entity_id' => $eventId,
            'role' => 'gallery',
        ]);

        // И он же виден в галерее мероприятия — иначе привязка была бы записью в
        // таблице, до которой не добраться через API.
        $gallery = $this->getJson('/api/v1/events/' . $eventId . '/gallery');
        $gallery->assertStatus(200);
        $gallery->assertJsonPath('meta.total', 1);
        $gallery->assertJsonPath('data.0.media.id', (int) $asset->id);
        $gallery->assertJsonPath('data.0.role', 'gallery');
        $gallery->assertJsonPath('data.0.position', 0);
    }

    public function test_an_existing_asset_can_be_attached_to_the_gallery(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);
        $asset = $this->makeAsset();

        $response = $this->postJson('/api/v1/events/' . $eventId . '/gallery', [
            'media_id' => (int) $asset->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.media_id', (int) $asset->id);
        $response->assertJsonPath('data.role', 'gallery');
        $response->assertJsonPath('data.media.url', $asset->url());
    }

    public function test_the_gallery_keeps_the_order_in_which_files_were_attached(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);

        $first = $this->makeAsset();
        $second = $this->makeAsset();
        $third = $this->makeAsset();

        foreach ([$first, $second, $third] as $asset) {
            $this->postJson('/api/v1/events/' . $eventId . '/gallery', [
                'media_id' => (int) $asset->id,
            ])->assertStatus(201);
        }

        $response = $this->getJson('/api/v1/events/' . $eventId . '/gallery');

        // Порядок — не «как получилось»: без вычисления position все связи
        // получили бы 0, и порядок определялся бы физическим порядком строк,
        // то есть был бы случаен после любой правки.
        $this->assertSame(
            [(int) $first->id, (int) $second->id, (int) $third->id],
            array_column($response->json('data'), 'media_id')
        );
        $this->assertSame([0, 1, 2], array_column($response->json('data'), 'position'));
    }

    public function test_attaching_the_same_file_twice_does_not_duplicate_the_link(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);
        $asset = $this->makeAsset();

        $this->postJson('/api/v1/events/' . $eventId . '/gallery', ['media_id' => (int) $asset->id])
            ->assertStatus(201);
        $this->postJson('/api/v1/events/' . $eventId . '/gallery', ['media_id' => (int) $asset->id])
            ->assertStatus(201);

        // `uq_media_links` запрещает дубль, поэтому вторая привязка обязана быть
        // идемпотентной: 500 от нарушения уникальности здесь был бы дефектом.
        $this->assertSame(1, MediaLink::query()->where('media_asset_id', $asset->id)->count());
    }

    public function test_a_file_can_be_removed_from_the_gallery(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);
        $asset = $this->makeAsset();

        $this->postJson('/api/v1/events/' . $eventId . '/gallery', ['media_id' => (int) $asset->id])
            ->assertStatus(201);

        $this->deleteJson('/api/v1/events/' . $eventId . '/gallery/' . $asset->id)->assertStatus(204);

        $this->assertDatabaseMissing('media_links', [
            'media_asset_id' => $asset->id,
            'entity_type' => 'event',
            'entity_id' => $eventId,
        ]);

        // Сам файл остаётся: он мог быть привязан к другому мероприятию, и
        // удалять его из галереи одного события нельзя.
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id, 'deleted_at' => null]);
    }

    /**
     * Снятие из галереи не трогает афишу.
     *
     * Роль — часть уникального ключа `uq_media_links`, поэтому один файл может
     * быть привязан к мероприятию и как `poster`, и как `gallery`. Кнопка
     * «убрать из галереи» обязана снять только вторую связь.
     */
    public function test_removing_from_the_gallery_keeps_the_poster_role(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);
        $asset = $this->makeAsset();

        DB::table('media_links')->insert([
            [
                'media_asset_id' => $asset->id,
                'entity_type' => 'event',
                'entity_id' => $eventId,
                'role' => 'poster',
                'position' => 0,
                'created_at' => now(),
            ],
            [
                'media_asset_id' => $asset->id,
                'entity_type' => 'event',
                'entity_id' => $eventId,
                'role' => 'gallery',
                'position' => 0,
                'created_at' => now(),
            ],
        ]);

        $this->deleteJson('/api/v1/events/' . $eventId . '/gallery/' . $asset->id)->assertStatus(204);

        $this->assertDatabaseHas('media_links', [
            'media_asset_id' => $asset->id,
            'entity_type' => 'event',
            'entity_id' => $eventId,
            'role' => 'poster',
        ]);

        $this->assertDatabaseMissing('media_links', [
            'media_asset_id' => $asset->id,
            'entity_type' => 'event',
            'entity_id' => $eventId,
            'role' => 'gallery',
        ]);
    }

    public function test_removing_a_file_that_is_not_in_the_gallery_is_a_404(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);
        $asset = $this->makeAsset();

        // 404, а не 204: иначе клиент не отличил бы «убрал» от «файла никогда
        // не было в галерее», и повторный клик выглядел бы успешным.
        $this->deleteJson('/api/v1/events/' . $eventId . '/gallery/' . $asset->id)->assertStatus(404);
    }

    /**
     * Мероприятие другой организации недоступно.
     *
     * Неявная привязка модели ищет по `id` без учёта арендатора, поэтому без
     * проверки администратор одной организации правил бы галерею чужого
     * мероприятия, зная его номер. 404, а не 403: 403 подтвердил бы, что
     * мероприятие существует.
     */
    public function test_an_event_of_another_organization_is_a_404(): void
    {
        $this->actingAsAdmin();

        $otherOrganizationId = $this->seedOrganization('Другая организация');
        $foreignEventId = $this->seedEvent($otherOrganizationId);

        $this->getJson('/api/v1/events/' . $foreignEventId . '/gallery')->assertStatus(404);

        $this->postJson('/api/v1/events/' . $foreignEventId . '/gallery', [
            'media_id' => (int) $this->makeAsset()->id,
        ])->assertStatus(404);
    }

    /**
     * Нечисловые идентификаторы не открывают чужие ресурсы.
     *
     * `/events/1abc/gallery` без `whereNumber` привёл бы путь к int и открыл
     * галерею мероприятия 1 — не тот ресурс, что запрошен.
     */
    public function test_non_numeric_ids_do_not_open_the_first_event(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);

        $this->getJson('/api/v1/events/' . $eventId . 'abc/gallery')->assertStatus(404);
    }

    public function test_the_gallery_only_returns_the_gallery_role(): void
    {        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);

        $poster = $this->makeAsset();
        $gallery = $this->makeAsset();

        DB::table('media_links')->insert([
            [
                'media_asset_id' => $poster->id,
                'entity_type' => 'event',
                'entity_id' => $eventId,
                'role' => 'poster',
                'position' => 0,
                'created_at' => now(),
            ],
            [
                'media_asset_id' => $gallery->id,
                'entity_type' => 'event',
                'entity_id' => $eventId,
                'role' => 'gallery',
                'position' => 0,
                'created_at' => now(),
            ],
        ]);

        $response = $this->getJson('/api/v1/events/' . $eventId . '/gallery');

        $this->assertSame([(int) $gallery->id], array_column($response->json('data'), 'media_id'));
    }

    /**
     * Связь с удалённым файлом не показывается.
     *
     * Мягкое удаление файла снимает связи явно, но проверить это нужно снаружи:
     * если бы фильтрация пропала, галерея отдавала бы карточку с `media: null`,
     * и фронтенд падал бы на попытке прочитать `url`.
     */
    public function test_a_soft_deleted_file_disappears_from_the_gallery(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);
        $asset = $this->makeAsset();

        $this->postJson('/api/v1/events/' . $eventId . '/gallery', ['media_id' => (int) $asset->id])
            ->assertStatus(201);

        $this->deleteJson('/api/v1/media/' . $asset->id)->assertStatus(204);

        $response = $this->getJson('/api/v1/events/' . $eventId . '/gallery');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
        $this->assertSame(0, $response->json('meta.total'));
    }

    /**
     * Галерея видна покупателю на странице мероприятия.
     *
     * Админская галерея без публичного показа — половина функции: файлы
     * загружены, привязаны и не видны никому, кроме администратора. `gallery`
     * приходит в том же ответе, что и само событие, поэтому открытие страницы
     * не требует второго запроса.
     *
     * Маршрут публичный — запрос идёт БЕЗ токена намеренно: если однажды на
     * него повесят `auth:api`, страница мероприятия останется без галереи, и
     * этот тест это покажет.
     */
    public function test_the_public_event_payload_carries_the_gallery(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);
        $asset = $this->makeAsset();

        $this->postJson('/api/v1/events/' . $eventId . '/gallery', ['media_id' => (int) $asset->id])
            ->assertStatus(201);

        $slug = (string) DB::table('events')->where('id', $eventId)->value('slug');

        $response = $this->getJson('/api/v1/events/by-slug/' . $slug);

        $response->assertStatus(200);
        $response->assertJsonPath('data.gallery.0.id', (int) $asset->id);
        $response->assertJsonPath('data.gallery.0.url', $asset->url());
        $response->assertJsonPath('data.gallery.0.mime_type', 'image/jpeg');
    }

    /**
     * В публичной галерее нет афиши и соблюдён порядок.
     *
     * Файл в роли `poster` показывается отдельным блоком страницы. Если бы он
     * попадал ещё и в галерею, афиша появлялась бы на странице дважды — и
     * заметить это можно было бы только глазами.
     */
    public function test_the_public_gallery_is_ordered_and_excludes_the_poster(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent($this->organizationId);
        $slug = (string) DB::table('events')->where('id', $eventId)->value('slug');

        $poster = $this->makeAsset();
        $first = $this->makeAsset();
        $second = $this->makeAsset();

        DB::table('media_links')->insert([
            [
                'media_asset_id' => $poster->id,
                'entity_type' => 'event',
                'entity_id' => $eventId,
                'role' => 'poster',
                'position' => 0,
                'created_at' => now(),
            ],
            [
                'media_asset_id' => $first->id,
                'entity_type' => 'event',
                'entity_id' => $eventId,
                'role' => 'gallery',
                'position' => 1,
                'created_at' => now(),
            ],
            [
                'media_asset_id' => $second->id,
                'entity_type' => 'event',
                'entity_id' => $eventId,
                'role' => 'gallery',
                'position' => 0,
                'created_at' => now(),
            ],
        ]);

        $response = $this->getJson('/api/v1/events/by-slug/' . $slug);

        $response->assertStatus(200);
        $this->assertSame(
            [(int) $second->id, (int) $first->id],
            array_column($response->json('data.gallery'), 'id')
        );
    }

    private function makeAsset(): MediaAsset
    {
        return MediaAsset::query()->create([
            'organization_id' => $this->organizationId,
            'disk' => 'public',
            'path' => 'media/' . Str::ulid()->toBase32() . '.jpg',
            'filename' => 'file.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);
    }

    private function seedOrganization(string $name = 'Тестовая организация'): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => $name,
            'slug' => 'org-' . Str::random(8),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedEvent(int $organizationId): int
    {
        $now = now();

        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $organizationId,
            'title' => 'Концерт',
            'slug' => 'event-' . Str::random(8),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function actingAsAdmin(): User
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
            'organization_id' => $this->organizationId,
            'role_id' => (int) $roleId,
            'created_at' => $now,
        ]);

        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }
}
