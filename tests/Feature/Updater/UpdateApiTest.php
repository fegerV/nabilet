<?php

declare(strict_types=1);

namespace Tests\Feature\Updater;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nabilet\Core\Http\Middleware\EnsurePlatformAdmin;
use Nabilet\Modules\Core\Users\Models\User;
use Nabilet\Modules\Updater\Http\Controllers\Admin\UpdateController;
use Nabilet\Modules\Updater\Services\UpdateStorage;
use Tests\TestCase;

/**
 * Админский API self-update (`/api/v1/admin/system/update/*`).
 *
 * Что здесь защищается:
 *  1. Эндпоинты живут под тем же контуром, что и системная диагностика:
 *     auth:api + admin + EnsurePlatformAdmin. Потеря любого middleware
 *     превращает кнопку обновления системы в публичный RPC.
 *  2. Запуск и откат требуют явной подтверждающей фразы — случайный клик
 *     в админке не должен менять код на сервере.
 *  3. HTTP только ставит задачу в очередь: тяжёлая работа идёт тикером крона,
 *     поэтому ответ 202, а запись остаётся в статусе `preparing`.
 *  4. Загруженный архив принимается только при совпадении SHA-256.
 */
final class UpdateApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> файлы, созданные тестом: удаляются в tearDown */
    private array $createdFiles = [];

    /** @var list<int> каталоги обновлений, созданные тестом */
    private array $updateIds = [];

    private ?User $admin = null;

    /** `start` копирует аварийный скрипт в storage — не трогаем чужую копию. */
    private bool $recoveryScriptExisted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recoveryScriptExisted = is_file(app(UpdateStorage::class)->recoveryScript());
    }

    protected function tearDown(): void
    {
        $storage = app(UpdateStorage::class);

        // `start` может поставить локальный бэкап в очередь и записать маркер.
        @unlink(storage_path('app/updates/local-backup.json'));

        foreach ($this->createdFiles as $path) {
            @unlink($path);
        }
        foreach ($this->updateIds as $id) {
            $storage->deleteTree($storage->base($id));
        }
        if (! $this->recoveryScriptExisted) {
            $script = $storage->recoveryScript();
            if (is_file($script)) {
                @unlink($script);
            }
            $dir = dirname($script);
            if (is_dir($dir) && count(scandir($dir) ?: []) <= 2) {
                @rmdir($dir);
            }
        }

        parent::tearDown();
    }

    public function test_all_update_routes_are_guarded_by_auth_admin_and_platform_admin(): void
    {
        $expected = [
            'check' => 'GET',
            'start' => 'POST',
            'upload' => 'POST',
            'status' => 'GET',
            'cancel' => 'POST',
            'rollback' => 'POST',
            'history' => 'GET',
        ];

        foreach ($expected as $action => $method) {
            $uri = 'api/v1/admin/system/update/' . $action;
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(static fn ($candidate): bool => $candidate->uri() === $uri);

            $this->assertNotNull($route, "Маршрут {$uri} должен быть зарегистрирован");
            $this->assertStringContainsString($method, $route->methods()[0], "Маршрут {$uri} должен принимать {$method}");

            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:api', $middleware, "{$uri} должен требовать аутентификацию");
            $this->assertContains('admin', $middleware, "{$uri} должен требовать роль администратора");
            $this->assertContains(
                EnsurePlatformAdmin::class,
                $middleware,
                "{$uri} должен быть доступен только платформенному администратору"
            );
            $this->assertStringContainsString(
                UpdateController::class,
                $route->getActionName(),
                "{$uri} должен указывать на UpdateController"
            );
        }
    }

    public function test_anonymous_requests_are_rejected(): void
    {
        foreach (['status', 'check', 'history'] as $action) {
            $response = $this->getJson('/api/v1/admin/system/update/' . $action);
            $response->assertUnauthorized();
            $this->assertSame(401, $response->getStatusCode(), "Аноним не должен читать /{$action} обновлений");
        }
    }

    public function test_start_requires_the_confirmation_phrase(): void
    {
        $response = $this->actingAs($this->admin(), 'api')->postJson('/api/v1/admin/system/update/start', [
            'version' => '1.17.0',
            'confirm_phrase' => 'NOT-UPDATE',
            'source' => 'feed',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'CONFIRMATION_REQUIRED');

        $this->assertSame(
            0,
            DB::table('system_updates')->count(),
            'Отклонённый запуск не должен создавать запись об обновлении'
        );
    }

    public function test_start_from_upload_queues_an_update_in_preparing(): void
    {
        Queue::fake();

        $storage = app(UpdateStorage::class);
        $storage->ensureDirectories(0);
        $storage->touchHeartbeat();
        $this->createdFiles[] = $storage->heartbeatFile();

        $contents = 'release-body-' . Str::random(16);
        $checksum = hash('sha256', $contents);
        $file = $checksum . '.zip';
        $path = $storage->uploads() . '/' . $file;
        file_put_contents($path, $contents);
        $this->createdFiles[] = $path;

        $response = $this->actingAs($this->admin(), 'api')->postJson('/api/v1/admin/system/update/start', [
            'version' => '1.17.0',
            'confirm_phrase' => 'update',
            'source' => 'upload',
            'file' => $file,
            'checksum' => $checksum,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'preparing');
        $this->assertSame(202, $response->getStatusCode(), 'Запуск должен возвращать 202, а не выполнять обновление в HTTP');

        $updateId = (int) $response->json('data.update_id');
        $this->assertGreaterThan(0, $updateId, 'В ответе должен быть идентификатор обновления');
        $this->updateIds[] = $updateId;

        $row = DB::table('system_updates')->where('id', $updateId)->first();
        $this->assertNotNull($row, 'Запись об обновлении должна быть создана');
        $this->assertSame('preparing', $row->status, 'Обновление должно ждать тика планировщика');
        $this->assertSame('1.17.0', $row->to_version, 'Целевая версия должна сохраняться из запроса');
        $this->assertSame('upload', $row->source, 'Источник должен быть ручная загрузка');
        $this->assertSame($checksum, $row->artifact_sha256, 'Контрольная сумма архива должна сохраняться');
        $this->assertSame($this->admin()->id, (int) $row->actor_id, 'Должен фиксироваться инициатор обновления');

        // `preflight` может поставить полный бэкап в очередь. Queue::fake()
        // удерживает задачу в памяти: sync-драйвер не должен вызывать mysqldump
        // внутри HTTP-теста.
    }

    public function test_upload_verifies_checksum_before_accepting_the_archive(): void
    {
        $storage = app(UpdateStorage::class);
        $storage->ensureDirectories(0);

        $contents = 'zip-bytes-' . Str::random(12);
        $checksum = hash('sha256', $contents);

        $wrong = $this->actingAs($this->admin(), 'api')->postJson('/api/v1/admin/system/update/upload', [
            'file' => UploadedFile::fake()->createWithContent('release.zip', $contents),
            'checksum' => str_repeat('0', 64),
        ]);

        $wrong->assertStatus(422)
            ->assertJsonPath('error.code', 'UPDATE_CHECKSUM_MISMATCH');
        $this->assertFileDoesNotExist(
            $storage->uploads() . '/' . str_repeat('0', 64) . '.zip',
            'Битый архив не должен попадать в рабочий каталог'
        );

        $ok = $this->actingAs($this->admin(), 'api')->postJson('/api/v1/admin/system/update/upload', [
            'file' => UploadedFile::fake()->createWithContent('release.zip', $contents),
            'checksum' => $checksum,
        ]);

        $ok->assertOk()
            ->assertJsonPath('data.file', $checksum . '.zip')
            ->assertJsonPath('data.checksum', $checksum)
            ->assertJsonPath('data.size', strlen($contents));

        $saved = $storage->uploads() . '/' . $checksum . '.zip';
        $this->createdFiles[] = $saved;
        $this->assertFileExists($saved, 'Проверенный архив должен быть сохранён');
        $this->assertSame($contents, file_get_contents($saved), 'Содержимое архива не должно изменяться');
    }

    public function test_status_is_idle_when_no_updates_have_run(): void
    {
        $this->actingAs($this->admin(), 'api')
            ->getJson('/api/v1/admin/system/update/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'idle')
            ->assertJsonPath('data.can_rollback', false);
    }

    public function test_rollback_requires_the_confirmation_phrase(): void
    {
        $this->actingAs($this->admin(), 'api')
            ->postJson('/api/v1/admin/system/update/rollback', [
                'confirm_phrase' => 'NOT-ROLLBACK',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CONFIRMATION_REQUIRED');
    }

    /**
     * Администратор: пользователь + роль + членство в организации.
     * Обе связи нужны: `admin` смотрит user_roles, `EnsurePlatformAdmin` —
     * ту же роль через OrganizationContext ( список платформенных e-mail пуст ).
     */
    private function admin(): User
    {
        if ($this->admin !== null) {
            return $this->admin;
        }

        $now = now();
        $organizationId = (int) DB::table('organizations')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'name' => 'Сургут-Концерт',
            'slug' => 'surgut-updater-' . Str::random(6),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $userId = (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid()->toBase32(),
            'email' => 'updater-admin@nabilet.local',
            'password' => bcrypt('Secret123456!'),
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $roleId = (int) (DB::table('roles')->where('slug', 'admin')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'Администратор',
                'slug' => 'admin',
                'description' => 'Полный доступ',
            ]));

        foreach (['user_organization', 'user_roles'] as $table) {
            DB::table($table)->insert([
                'user_id' => $userId,
                'organization_id' => $organizationId,
                'role_id' => $roleId,
                'created_at' => $now,
            ]);
        }

        return $this->admin = User::query()->findOrFail($userId);
    }
}
