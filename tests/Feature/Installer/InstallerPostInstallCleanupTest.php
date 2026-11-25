<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Nabilet\Modules\Installer\Http\Controllers\InstallerController;
use Nabilet\Modules\Installer\Support\SetupArtifactCleaner;
use Tests\TestCase;

/**
 * Зачистка следов развёртывания и закрытие мастера после установки.
 *
 * Проверяем две независимые вещи:
 *  1. `SetupArtifactCleaner` удаляет ТОЛЬКО белый список имён и только в двух
 *     корнях (любые «похожие» файлы обязаны остаться);
 *  2. при наличии `storage/install.lock` маршруты /install вообще не
 *     регистрируются — запрос уходит в брендированную 404.
 */
final class InstallerPostInstallCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Тест по lock временно создаёт реальный файл в storage. Обязательно
        // убираем: иначе все последующие installer-тесты увидят «установлено».
        $lock = storage_path('install.lock');
        if (is_file($lock)) {
            File::delete($lock);
        }
        // refreshApplication() с lock поднимает провайдер, который ставит метку
        // пост-выкладочной зачистки. В тесте она ничего не защищает — убираем.
        foreach (glob(storage_path('framework/setup-purged-*.marker')) ?: [] as $marker) {
            File::delete($marker);
        }

        parent::tearDown();
    }

    public function test_cleaner_removes_only_whitelisted_files_and_legacy_installer_directory(): void
    {
        $root = sys_get_temp_dir() . '/nabilet-cleaner-' . bin2hex(random_bytes(6));
        $public = $root . '/public';
        File::ensureDirectoryExists($public);

        File::put($root . '/info.php', '<?php phpinfo();');
        File::put($public . '/info.php', '<?php phpinfo();');
        File::put($public . '/phpinfo.php', '<?php phpinfo();');
        File::put($root . '/install.php', '<?php // legacy');
        File::ensureDirectoryExists($root . '/install');
        File::put($root . '/install/index.php', '<?php // legacy wizard');
        // Непохожие файлы и каталоги должны пережить зачистку.
        File::put($root . '/index.php', '<?php // front controller');
        File::put($public . '/readme.txt', 'keep me');
        File::ensureDirectoryExists($public . '/install');
        File::put($public . '/install/settings.json', '{}');

        try {
            $removed = (new SetupArtifactCleaner($root, $public))->purge();
            sort($removed);

            // Пути ОТНОСИТЕЛЬНО корня проекта (документированный контракт):
            // файлы из public/ обязаны сохранять префикс на Laravel и Windows.
            $this->assertSame([
                'info.php',
                'install.php',
                'install/',
                'public/info.php',
                'public/phpinfo.php',
            ], $removed);

            $this->assertFileDoesNotExist($root . '/info.php');
            $this->assertFileDoesNotExist($public . '/info.php');
            $this->assertFileDoesNotExist($public . '/phpinfo.php');
            $this->assertFileDoesNotExist($root . '/install.php');
            $this->assertDirectoryDoesNotExist($root . '/install');
            $this->assertFileExists($root . '/index.php');
            $this->assertFileExists($public . '/readme.txt');
            // Каталог без точки входа — не legacy-установщик, трогать нельзя.
            $this->assertFileExists($public . '/install/settings.json');
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_purge_once_writes_a_version_marker_and_repeats_nothing(): void
    {
        $root = sys_get_temp_dir() . '/nabilet-cleaner-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($root . '/storage/framework');
        File::put($root . '/info.php', '<?php phpinfo();');

        try {
            $cleaner = new SetupArtifactCleaner($root, $root . '/public');

            $first = $cleaner->purgeOnce('1.4.1');
            $this->assertSame(['info.php'], $first);
            $this->assertFileExists($cleaner->markerPath('1.4.1'));

            File::put($root . '/info.php', '<?php phpinfo();');
            $this->assertSame([], $cleaner->purgeOnce('1.4.1'), 'метка не сработала');

            // Новая версия = новая выкладка: проверка повторяется.
            $this->assertSame(['info.php'], $cleaner->purgeOnce('1.4.2'));
        } finally {
            File::deleteDirectory($root);
        }
    }

    /**
     * Интеграция с шагом 9 мастера: lock, зачистка, сброс кеша маршрутов и
     * список удалённого в ответе — всё в одном вызове.
     */
    public function test_mark_as_installed_purges_artifacts_and_returns_them(): void
    {
        $artifact = base_path('info.php');
        File::put($artifact, '<?php phpinfo();');

        try {
            $removed = (new \ReflectionMethod(InstallerController::class, 'markAsInstalled'))
                ->invoke(new InstallerController());

            $this->assertContains('info.php', $removed);
            $this->assertFileDoesNotExist($artifact, 'diagnostic file survived the installer');
            $this->assertFileExists(storage_path('install.lock'));
        } finally {
            File::delete($artifact);
        }
    }

    public function test_installer_routes_are_absent_after_installation(): void
    {
        File::put(storage_path('install.lock'), now()->toIso8601String());

        // Маршруты регистрируются при загрузке приложения — нужен свежий boot.
        $this->refreshApplication();

        $this->assertFalse(Route::has('installer.show'));
        $this->assertFalse(Route::has('installer.install'));
        $this->get('/install')->assertNotFound();
        $this->getJson('/install')->assertNotFound();
        // Laravel registers Route::fallback() for GET only; POST without a
        // matching route returns 405 — master is unreachable either way.
        $this->postJson('/install', [])->assertStatus(405);
        $this->postJson('/install/test-database', [])->assertStatus(405);
    }
}
