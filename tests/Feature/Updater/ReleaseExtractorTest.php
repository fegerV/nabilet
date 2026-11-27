<?php

declare(strict_types=1);

namespace Tests\Feature\Updater;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Nabilet\Core\Errors\AppError;
use Nabilet\Modules\Updater\Services\ReleaseExtractor;
use Nabilet\Modules\Updater\Services\UpdateStorage;
use Tests\TestCase;

/**
 * Безопасная распаковка релиза (`ReleaseExtractor`).
 *
 * Архив — враждебный ввод: `safeName()` решает, что попадёт в staging.
 * Он выбрасывает AppError для путей с `..`, для файлов вне whitelist и для
 * скрытых файлов внутри каталогов релиза, но МОЛЧА ПРОПУСКАЕТ
 * `bootstrap/cache/**`, `storage/**` и неразрешённые файлы в `public_html`
 * (например, `public_html/images/**`). Служебные `.gitignore` и метаданные
 * сторонних пакетов в `vendor/` разрешены: иначе реальный архив с prod-
 * зависимостями не проходит распаковку. `scripts/updater-recover.php` входит
 * в whitelist, потому что Updater копирует его в `storage/` при старте.
 */
final class ReleaseExtractorTest extends TestCase
{
    use RefreshDatabase;

    /** Уникальный идентификатор, чтобы не задевать рабочие каталоги обновлений. */
    private const UPDATE_ID = 990001;

    /** @var list<string> временные ZIP-файлы */
    private array $zips = [];

    protected function tearDown(): void
    {
        $storage = app(UpdateStorage::class);
        $storage->deleteTree($storage->base(self::UPDATE_ID));

        foreach ($this->zips as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_required_and_allowed_files_are_extracted_into_staging(): void
    {
        $zip = $this->writeZip([
            'bootstrap/app.php' => '<?php // bootstrap',
            'config/app.php' => '<?php // config',
            'public/index.php' => '<?php // front controller',
            'public_html/index.php' => '<?php // docroot entry',
            'vendor/autoload.php' => '<?php // autoload',
            'artisan' => '<?php // console entry',
            'app/Modules/Demo/NewController.php' => '<?php // application code',
            'config/new.php' => '<?php // new config',
            'public_html/assets/app.js' => 'console.log("new");',
            'public_html/robots.txt' => 'User-agent: *',
            // Служебная запись bootstrap/cache должна быть пропущена без ошибки.
            'bootstrap/cache/.gitignore' => "*\n!.gitignore\n",
        ]);

        $result = app(ReleaseExtractor::class)->extract($zip, self::UPDATE_ID);

        // Одиннадцать записей в архиве, но распаковано десять: кэш пропущен
        // намеренно.
        $this->assertSame(10, $result['entries'], 'В staging должны попасть только разрешённые файлы');
        $this->assertGreaterThan(0, $result['bytes'], 'Распакованные байты должны учитываться');

        $staging = app(UpdateStorage::class)->staging(self::UPDATE_ID);
        foreach ([
            'bootstrap/app.php',
            'config/app.php',
            'public/index.php',
            'public_html/index.php',
            'vendor/autoload.php',
            'artisan',
            'app/Modules/Demo/NewController.php',
            'config/new.php',
            'public_html/assets/app.js',
            'public_html/robots.txt',
        ] as $file) {
            $this->assertFileExists($staging . '/' . $file, "Файл {$file} должен быть распакован");
        }

        $this->assertSame('<?php // application code', file_get_contents($staging . '/app/Modules/Demo/NewController.php'));
        $this->assertFileDoesNotExist($staging . '/bootstrap/cache/.gitignore', 'Скомпилированный кэш не должен приезжать из архива');
    }

    public function test_parent_directory_traversal_is_rejected(): void
    {
        $zip = $this->writeZip($this->requiredFiles() + ['../evil.php' => '<?php malicious();']);

        $error = $this->extractExpecting('UPDATE_ARCHIVE_UNSAFE', $zip);
        $this->assertStringContainsString('небезопасный путь', $error->getMessage());
    }

    public function test_dotenv_from_the_archive_is_rejected(): void
    {
        $zip = $this->writeZip($this->requiredFiles() + ['.env' => 'APP_KEY=stolen']);

        $error = $this->extractExpecting('UPDATE_ARCHIVE_UNSAFE', $zip);
        $this->assertStringContainsString('вне разрешённых каталогов', $error->getMessage());

        $this->assertFileDoesNotExist(
            app(UpdateStorage::class)->staging(self::UPDATE_ID) . '/.env',
            'Файл .env из архива не должен появляться даже частично'
        );
    }

    public function test_storage_entry_is_skipped_and_unlisted_docroot_files_are_skipped(): void
    {
        // Архив содержит пустые .gitignore-каркасы storage/, но ни один файл
        // оттуда не должен попасть в staging и заменить данные установки.
        $storageZip = $this->writeZip($this->requiredFiles() + [
            'storage/app/public/.gitignore' => "*\n!.gitignore\n",
            'storage/secret.txt' => 'user data must survive the update',
        ]);

        $result = app(ReleaseExtractor::class)->extract($storageZip, self::UPDATE_ID);
        $this->assertSame(6, $result['entries'], 'Записи storage/ пропускаются');
        $this->assertFileDoesNotExist(
            app(UpdateStorage::class)->staging(self::UPDATE_ID) . '/storage/secret.txt',
            'Пользовательские данные не должны попадать в staging'
        );

        // Картинка витрины уже внутри разрешённого public_html, но не входит
        // в PUBLIC_HTML_FILES / PUBLIC_HTML_DIRS — такая запись молча пропускается.
        $imagesZip = $this->writeZip($this->requiredFiles() + [
            'public_html/images/poster.jpg' => 'binary',
        ]);

        $result = app(ReleaseExtractor::class)->extract($imagesZip, self::UPDATE_ID);

        $this->assertSame(6, $result['entries'], 'Картинки витрины должны быть пропущены');
        $this->assertFileDoesNotExist(
            app(UpdateStorage::class)->staging(self::UPDATE_ID) . '/public_html/images/poster.jpg',
            'Картинки витрины не входят в whitelist публичных файлов'
        );
    }

    public function test_real_release_entries_are_allowed(): void
    {
        // ровно то, что кладёт scripts/build-hosting-zip.php в архив
        $zip = $this->writeZip($this->requiredFiles() + [
            '.env.example' => 'APP_NAME=NABILET',
            'vendor/laravel/framework/.gitignore' => "*\n",
            'vendor/laravel/mcp/.agents/resume' => '{}',
            'dist/storage/.gitignore' => "*\n",
            'scripts/updater-recover.php' => '<?php // emergency recovery',
        ]);

        $result = app(ReleaseExtractor::class)->extract($zip, self::UPDATE_ID);
        $this->assertSame(11, $result['entries']);
        $staging = app(UpdateStorage::class)->staging(self::UPDATE_ID);
        $this->assertFileExists($staging . '/scripts/updater-recover.php');
        $this->assertFileExists($staging . '/vendor/laravel/mcp/.agents/resume');
        $this->assertFileExists($staging . '/.env.example');
    }

    public function test_missing_required_file_is_rejected(): void
    {
        $required = $this->requiredFiles();
        unset($required['vendor/autoload.php']);

        $error = $this->extractExpecting('UPDATE_ARCHIVE_INCOMPLETE', $this->writeZip($required));

        $this->assertStringContainsString('vendor/autoload.php', $error->getMessage());
    }

    /** @return array<string, string> */
    private function requiredFiles(): array
    {
        return [
            'bootstrap/app.php' => '<?php // bootstrap',
            'config/app.php' => '<?php // config',
            'public/index.php' => '<?php // front controller',
            'public_html/index.php' => '<?php // docroot entry',
            'vendor/autoload.php' => '<?php // autoload',
            'artisan' => '<?php // console entry',
        ];
    }

    /** @param array<string, string> $files */
    private function writeZip(array $files): string
    {
        $path = sys_get_temp_dir() . '/nabilet-release-' . Str::random(12) . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue(
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true,
            'Не удалось создать временный ZIP для теста'
        );

        foreach ($files as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents), "Не удалось добавить {$name} в архив");
        }
        $zip->close();

        $this->zips[] = $path;

        return $path;
    }

    private function extractExpecting(string $code, string $zip): AppError
    {
        try {
            app(ReleaseExtractor::class)->extract($zip, self::UPDATE_ID);
        } catch (AppError $e) {
            $this->assertSame($code, $e->errorCode, 'Код ошибки распаковки должен быть стабильным');

            return $e;
        }

        $this->fail("Ожидалась ошибка {$code}, но распаковка завершилась успешно");
    }
}
