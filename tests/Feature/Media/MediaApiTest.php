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
use Tests\TestCase;

/**
 * Модуль Media по HTTP.
 *
 * ЧТО ЗДЕСЬ НА САМОМ ДЕЛЕ ПРОВЕРЯЕТСЯ
 *
 * Модуль выглядел написанным: был `module.json`, был провайдер с вызовом
 * `loadRoutesFrom`. Провайдер не регистрировался, файла маршрутов не
 * существовало, и ни один из пяти эндпоинтов контракта не отвечал. Поэтому
 * тесты идут ЧЕРЕЗ HTTP, а не через сервис: проверка «сервис умеет сохранять
 * файл» прошла бы и на мёртвом модуле. Здесь важно, что маршрут существует,
 * что middleware на нём те, что объявлены, и что ответ совпадает с контрактом.
 *
 * ФАЙЛЫ НЕ ПИШУТСЯ НА НАСТОЯЩИЙ ДИСК
 *
 * `Storage::fake('public')` подменяет диск, поэтому тест не зависит от прав на
 * `storage/app/public` и не оставляет мусор между прогонами. При этом
 * проверяется, что файл действительно записан (`assertExists`) — иначе
 * «загрузили» означало бы «создали запись в базе», а файла бы не было.
 */
class MediaApiTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    /**
     * Минимальный контейнер MP4: только `ftyp`-бокс. Тип определяется по нему.
     *
     * Файл нужен настоящий, а не `fake()->create()`: тот создаёт поток нулей, и
     * определение типа по содержимому даёт `application/octet-stream` — проверка
     * `mimes:` отвергла бы файл раньше, чем дело дошло бы до списка типов.
     */
    private const MP4_BYTES = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41";

    /** Заголовок EBML с DocType `webm` — по нему определяется тип. */
    private const WEBM_BYTES = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01"
        . "\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x02\x42\x85\x81\x02";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->organizationId = $this->seedOrganization();
    }

    public function test_the_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/media')->assertStatus(401);
    }

    public function test_an_uploaded_file_becomes_an_asset_and_really_lands_on_the_disk(): void
    {
        $this->actingAsAdmin();

        $response = $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('afisha.jpg', 1200, 800),
            'title' => 'Афиша',
            'alt_text' => 'Главная афиша концерта',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);

        $asset = MediaAsset::query()->firstOrFail();

        $this->assertSame('afisha.jpg', $asset->filename);
        $this->assertSame(1200, (int) $asset->width);
        $this->assertSame(800, (int) $asset->height);
        $this->assertSame('public', $asset->disk);
        $this->assertNotNull($asset->checksum, 'SHA-256 не посчитан');
        $this->assertSame(64, strlen((string) $asset->checksum), 'checksum должен быть CHAR(64)');
        $this->assertStringStartsWith('media/', (string) $asset->path);

        // Файл действительно записан: без этого «загрузка» была бы только
        // строкой в базе.
        Storage::disk('public')->assertExists((string) $asset->path);

        // URL строится через AssetUrl: префикс /storage/ — иначе файл, лежащий
        // в storage/app/public, отдавался бы из корня и давал 404.
        $this->assertStringContainsString('/storage/media/', (string) $response->json('data.url'));

        // Путь на диске не должен повторять имя файла клиента: иначе две
        // загрузки `afisha.jpg` перезаписали бы друг друга.
        $this->assertStringNotContainsString('afisha', (string) $asset->path);
    }

    public function test_uploading_requires_the_admin_role(): void
    {
        $this->actingAsStaffWithoutRoles();

        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('afisha.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(403);

        $this->assertSame(0, MediaAsset::query()->count());
    }

    public function test_a_file_type_outside_the_allow_list_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->create('payload.exe', 5, 'application/x-msdownload'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, MediaAsset::query()->count());
    }

    /**
     * Вторая дорога создания: файл уже лежит в хранилище, запись только
     * описывает его. Именно её описывает контракт (`MediaAssetCreate` требует
     * `filename`, `mime_type`, `path` и не содержит поля с файлом).
     */
    public function test_a_file_can_be_registered_by_path_without_uploading(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/media', [
            'filename' => 'poster.webp',
            'mime_type' => 'image/webp',
            'path' => 'media/external-upload.webp',
            'size_bytes' => 4096,
            'width' => 800,
            'height' => 600,
        ]);

        $response->assertStatus(201);

        $asset = MediaAsset::query()->firstOrFail();
        $this->assertSame('media/external-upload.webp', $asset->path);
        $this->assertSame(4096, (int) $asset->size_bytes);
        $this->assertNull($asset->checksum, 'при регистрации по ссылке контрольная сумма неизвестна');
    }

    public function test_a_registered_file_still_requires_the_contract_fields(): void
    {
        $this->actingAsAdmin();

        // Контракт объявляет required: [filename, mime_type, path]. Запрос без
        // `path` обязан быть отклонён, а не создать запись с пустым путём —
        // иначе в галерее появится файл, который никуда не ведёт.
        $this->postJson('/api/v1/media', [
            'filename' => 'poster.webp',
            'mime_type' => 'image/webp',
        ])->assertStatus(422);
    }

    public function test_the_asset_can_be_fetched_by_id(): void
    {
        $this->actingAsAdmin();

        $asset = $this->makeAsset();

        $response = $this->getJson('/api/v1/media/' . $asset->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', (int) $asset->id);
        $response->assertJsonPath('data.public_id', (string) $asset->public_id);
    }

    public function test_metadata_can_be_updated(): void
    {
        $this->actingAsAdmin();

        $asset = $this->makeAsset();

        $response = $this->patchJson('/api/v1/media/' . $asset->id, [
            'title' => 'Новая афиша',
            'alt_text' => 'Описание для незрячих',
        ]);

        $response->assertStatus(200);

        $asset->refresh();
        $this->assertSame('Новая афиша', $asset->title);
        $this->assertSame('Описание для незрячих', $asset->alt_text);
    }

    /**
     * Удаление мягкое, файл остаётся.
     *
     * Это не деталь реализации, а обещание: администратор нажимает «удалить»
     * часто по ошибке, и запись без файла восстановить нечем.
     */
    public function test_deleting_an_asset_soft_deletes_it_and_keeps_the_file(): void
    {
        $this->actingAsAdmin();

        $asset = $this->makeAsset();
        $path = (string) $asset->path;

        $this->deleteJson('/api/v1/media/' . $asset->id)->assertStatus(204);

        $this->assertSoftDeleted('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_deleted_asset_is_not_returned_by_the_list(): void
    {
        $this->actingAsAdmin();

        $asset = $this->makeAsset();
        $this->deleteJson('/api/v1/media/' . $asset->id)->assertStatus(204);

        $response = $this->getJson('/api/v1/media');
        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_an_unknown_asset_is_a_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/media/999999')->assertStatus(404);
    }

    /**
     * Нечисловой идентификатор не открывает первый файл.
     *
     * Без `whereNumber` неявная привязка приводит `/media/1abc` к int, и
     * запрос молча открывает файл 1 — не тот ресурс, что запрошен. Такая же
     * ловушка уже случалась в модуле Tickets.
     */
    public function test_a_non_numeric_id_does_not_open_the_first_asset(): void
    {
        $this->actingAsAdmin();

        $asset = $this->makeAsset();

        $this->getJson('/api/v1/media/' . $asset->id . 'abc')->assertStatus(404);
    }

    public function test_the_list_can_be_filtered_to_one_object(): void
    {
        $this->actingAsAdmin();

        $attached = $this->makeAsset();
        $other = $this->makeAsset();

        DB::table('media_links')->insert([
            'media_asset_id' => $attached->id,
            'entity_type' => 'event',
            'entity_id' => 4242,
            'role' => 'gallery',
            'position' => 0,
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/media?entity_type=event&entity_id=4242');

        $response->assertStatus(200);
        $ids = array_column($response->json('data'), 'id');

        $this->assertSame([(int) $attached->id], $ids);
        $this->assertNotContains((int) $other->id, $ids);
        $this->assertSame(1, $response->json('meta.total'));
    }

    /**
     * Список отдаёт связи файла, а не только его метаданные.
     *
     * Библиотека файлов показывает, к чему привязан файл: администратор решает,
     * удалять ли файл, глядя на список. Без `links` в ответе списка он удалял бы
     * вслепую — вместе с файлом уехала бы афиша или галерея мероприятия.
     *
     * Проверяются ОБЕ ветки `index()`: общий список и режим «галерея объекта».
     * Они собирают ответ по-разному, и одна из них уже могла бы молча потерять
     * поле — тогда в списке файлов связь видна, а в галерее нет.
     */
    public function test_the_list_shows_what_each_file_is_attached_to(): void
    {
        $this->actingAsAdmin();

        $attached = $this->makeAsset();
        $loose = $this->makeAsset();

        DB::table('media_links')->insert([
            'media_asset_id' => $attached->id,
            'entity_type' => 'event',
            'entity_id' => 777,
            'role' => 'gallery',
            'position' => 0,
            'created_at' => now(),
        ]);

        $rows = collect($this->getJson('/api/v1/media')->assertStatus(200)->json('data'))
            ->keyBy('id');

        $this->assertSame('event', $rows[(int) $attached->id]['links'][0]['entity_type']);
        $this->assertSame(777, $rows[(int) $attached->id]['links'][0]['entity_id']);
        $this->assertSame('gallery', $rows[(int) $attached->id]['links'][0]['role']);

        // У непривязанного файла ключ есть, но список пуст: `links` присутствует,
        // потому что связь загружена, — «нет связей» и «поле не пришло» это
        // разные вещи, и клиент вправе на них опираться.
        $this->assertSame([], $rows[(int) $loose->id]['links']);

        $filtered = collect(
            $this->getJson('/api/v1/media?entity_type=event&entity_id=777')
                ->assertStatus(200)
                ->json('data')
        )->keyBy('id');

        $this->assertSame(777, $filtered[(int) $attached->id]['links'][0]['entity_id']);
    }

    public function test_per_page_is_capped(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/media?per_page=100000');

        $response->assertStatus(200);
        $this->assertSame(100, $response->json('meta.per_page'));
    }

    /**
     * Видео принимается — потому что интерфейс его предлагает.
     *
     * `GALLERY_TYPES` (`resources/js/lib/eventGallery.ts`) содержит `video/mp4`
     * и `video/webm`, поле выбора файла объявляет их в `accept`, а витрина
     * рисует всё, что не начинается с `image/`, как `<video>`. Серверный список
     * типов при этом видео НЕ содержал: администратор выбирал файл, который
     * интерфейс только что предложил, и получал 422 «должен быть одного из
     * типов: jpg, jpeg, png, …». Тест держит обещание интерфейса и список типов
     * вместе — разъехавшись, они снова дадут тупик без внятной причины.
     */
    public function test_video_types_are_accepted_because_the_gallery_offers_them(): void
    {
        $this->actingAsAdmin();

        $cases = [
            ['clip.mp4', self::MP4_BYTES, 'video/mp4'],
            ['clip.webm', self::WEBM_BYTES, 'video/webm'],
        ];

        foreach ($cases as [$name, $bytes, $expectedMime]) {
            $response = $this->post('/api/v1/media', [
                'file' => $this->uploadedFile($name, $bytes, $expectedMime),
            ]);

            $response->assertStatus(201);
            $this->assertSame($name, $response->json('data.filename'));
            $this->assertSame($expectedMime, $response->json('data.mime_type'), $name);
        }
    }

    /**
     * Тип файла берётся из содержимого, а не из заявки клиента.
     *
     * От `mime_type` зависит, что увидит покупатель: витрина решает по нему,
     * рисовать картинку или значок видео. Раньше в базу писался
     * `getClientMimeType()` — то, что сказал клиент. Клиент, отправивший
     * `application/octet-stream` (curl, часть инструментов, ОС без сопоставления
     * расширения), записывал этот тип, и фотография показывалась как «🎬».
     *
     * Здесь клиент намеренно врёт про тип, а файл — настоящий PNG.
     */
    public function test_the_stored_mime_type_comes_from_the_content_not_the_client(): void
    {
        $this->actingAsAdmin();

        $response = $this->post('/api/v1/media', [
            'file' => $this->uploadedFile('photo.png', $this->pngBytes(100, 60), 'application/octet-stream'),
        ]);

        $response->assertStatus(201);
        $this->assertSame('image/png', $response->json('data.mime_type'));
        $this->assertSame(100, $response->json('data.width'));
        $this->assertSame(60, $response->json('data.height'));
    }

    /**
     * Пределы длины совпадают с колонками схемы.
     *
     * `MediaRole::MAX_LENGTH` (64) и `MediaEntityType::MAX_LENGTH` (100) —
     * константы рядом с колонками `media_links.role VARCHAR(64)` и
     * `entity_type VARCHAR(100)`. Проверка идёт ЧЕРЕЗ базу: смысл в том, что
     * значение, прошедшее валидацию, действительно помещается в колонку, а
     * значение на символ длиннее получает 422, а не 500 от MySQL (строгий режим
     * отвечает ошибкой 1406 на слишком длинное значение).
     *
     * ЭТОТ ТЕСТ УЖЕ НАШЁЛ ДЕФЕКТ: `entity_type`, `role` и `position` не
     * валидировались в `POST /media` вообще, и слишком длинное значение
     * доходило до вставки. Теперь 422 приходит из валидатора.
     */
    public function test_role_and_entity_type_limits_match_the_columns(): void
    {
        $this->actingAsAdmin();
        $eventId = $this->seedEvent();

        $role64 = str_repeat('r', 64);

        $this->postJson('/api/v1/events/' . $eventId . '/gallery', [
            'media_id' => (int) $this->makeAsset()->id,
            'role' => $role64,
        ])->assertStatus(201);

        $this->assertDatabaseHas('media_links', [
            'entity_type' => 'event',
            'entity_id' => $eventId,
            'role' => $role64,
        ]);

        $this->postJson('/api/v1/events/' . $eventId . '/gallery', [
            'media_id' => (int) $this->makeAsset()->id,
            'role' => str_repeat('r', 65),
        ])->assertStatus(422);

        $entityType100 = str_repeat('t', 100);

        $this->postJson('/api/v1/media', [
            'filename' => 'a.jpg',
            'mime_type' => 'image/jpeg',
            'path' => 'media/a.jpg',
            'entity_type' => $entityType100,
            'entity_id' => 7,
        ])->assertStatus(201);

        $this->assertDatabaseHas('media_links', [
            'entity_type' => $entityType100,
            'entity_id' => 7,
        ]);

        $this->postJson('/api/v1/media', [
            'filename' => 'b.jpg',
            'mime_type' => 'image/jpeg',
            'path' => 'media/b.jpg',
            'entity_type' => str_repeat('t', 101),
            'entity_id' => 7,
        ])->assertStatus(422);
    }

    /**
     * Неоднозначная правка связи — 422, а не «обновим первую попавшуюся».
     *
     * `role` и `position` в `MediaAssetUpdate` — поля СВЯЗИ, а файл может быть
     * привязан к нескольким объектам сразу. Молчаливая правка первой связи
     * означала бы, что администратор правит карточку в одной галерее, а
     * меняется другая.
     */
    public function test_updating_a_link_position_without_an_entity_is_refused_when_ambiguous(): void
    {
        $this->actingAsAdmin();
        $asset = $this->makeAsset();

        foreach ([1, 2] as $entityId) {
            DB::table('media_links')->insert([
                'media_asset_id' => $asset->id,
                'entity_type' => 'event',
                'entity_id' => $entityId,
                'role' => 'gallery',
                'position' => 0,
                'created_at' => now(),
            ]);
        }

        $this->patchJson('/api/v1/media/' . $asset->id, ['position' => 5])->assertStatus(422);
    }

    public function test_updating_a_link_position_works_when_the_file_has_one_link(): void
    {
        $this->actingAsAdmin();
        $asset = $this->makeAsset();

        DB::table('media_links')->insert([
            'media_asset_id' => $asset->id,
            'entity_type' => 'event',
            'entity_id' => 1,
            'role' => 'gallery',
            'position' => 0,
            'created_at' => now(),
        ]);

        $this->patchJson('/api/v1/media/' . $asset->id, ['position' => 5])->assertStatus(200);

        $this->assertDatabaseHas('media_links', [
            'media_asset_id' => $asset->id,
            'entity_type' => 'event',
            'entity_id' => 1,
            'position' => 5,
        ]);
    }

    /**
     * Настоящий загруженный файл с заданным содержимым и ЗАЯВЛЕННЫМ типом.
     *
     * `UploadedFile::fake()` для этого не годится: его `mimeType()` подменяет и
     * `getMimeType()`, поэтому «клиент соврал про тип» в тесте превратилось бы
     * в «файл действительно такого типа» — проверка ничего бы не проверяла.
     * Здесь тип, названный клиентом, и тип, определённый по содержимому, —
     * разные значения, как и в реальном запросе.
     */
    private function uploadedFile(string $name, string $bytes, string $clientMime): UploadedFile
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nabilet-media-' . Str::random(8);
        mkdir($dir);

        $path = $dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $clientMime, null, true);
    }

    /** Настоящий PNG заданного размера — содержимое, а не подпись типа. */
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    private function makeAsset(): MediaAsset
    {
        $path = 'media/' . Str::ulid()->toBase32() . '.jpg';

        // Файл пишется на подменённый диск намеренно: без него проверка
        // «удаление записи не удаляет файл» ничего не проверяла бы — файла не
        // было бы ни до, ни после.
        Storage::disk('public')->put($path, 'not-a-real-image');

        return MediaAsset::query()->create([
            'organization_id' => $this->organizationId,
            'disk' => 'public',
            'path' => $path,
            'filename' => 'file.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
        ]);
    }

    private function seedOrganization(): int
    {
        $now = now();

        return (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Тестовая организация',
            'slug' => 'test-org-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedEvent(): int
    {
        $now = now();

        return (int) DB::table('events')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'organization_id' => $this->organizationId,
            'title' => 'Концерт',
            'slug' => 'event-' . Str::random(8),
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Администратор: middleware `admin` читает `user_roles`, а не колонку в
     * `users` (см. `StaffRole::isStaff()`).
     */
    private function actingAsAdmin(): User
    {
        return $this->actingAsUserWithRole('admin');
    }

    /** Пользователь без ролей — 403 от `admin`, а не 401: он аутентифицирован. */
    private function actingAsStaffWithoutRoles(): User
    {
        $userId = $this->insertUser();

        $user = User::query()->findOrFail($userId);
        $this->actingAs($user, 'api');

        return $user;
    }

    private function actingAsUserWithRole(string $roleSlug): User
    {
        $now = now();
        $userId = $this->insertUser();

        $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');

        if ($roleId === null) {
            $roleId = (int) DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => $roleSlug,
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

    private function insertUser(): int
    {
        $now = now();

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'status' => 'active',
            'locale' => 'ru',
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
