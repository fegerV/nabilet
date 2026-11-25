<?php

declare(strict_types=1);

namespace Tests\Feature\Updater;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Nabilet\Modules\Updater\Services\UpdateApplier;
use Nabilet\Modules\Updater\Services\UpdateStorage;
use Tests\TestCase;

/**
 * Подмена кода и откат (`UpdateApplier`) на изолированном фикстурном сайте.
 *
 * Реальный корень приложения в тестах не трогается: `apply()` и `rollback()`
 * принимают `$root` как test seam. Проверяется главное обещание апplier'а —
 * код меняется целиком и атомарно, а пользовательские данные (.env, storage,
 * .htaccess, картинки витрины) остаются байт-в-байт прежними и не перезаписываются
 * каталогом отката.
 */
final class UpdateApplyTest extends TestCase
{
    use RefreshDatabase;

    /** Уникальный идентификатор, чтобы не задевать рабочие каталоги обновлений. */
    private const UPDATE_ID = 990002;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // Если предыдущий тест оставил сайт в режиме обслуживания, отмена
        // здесь делает стартовое состояние предсказуемым.
        app(MaintenanceMode::class)->deactivate();

        $this->root = sys_get_temp_dir() . '/nabilet-apply-root-' . Str::random(10);
        $this->makeFixtureRoot();
    }

    protected function tearDown(): void
    {
        // Ни один тест не должен оставить приложение в режиме обслуживания,
        // даже если apply()/rollback() бросили исключение.
        app(MaintenanceMode::class)->deactivate();

        $storage = app(UpdateStorage::class);
        $storage->deleteTree($storage->base(self::UPDATE_ID));
        $storage->deleteTree($this->root);

        parent::tearDown();
    }

    public function test_apply_swaps_code_preserves_user_files_and_keeps_a_backup(): void
    {
        $protected = $this->protectedSnapshot();
        $this->buildStaging();

        app(UpdateApplier::class)->apply(self::UPDATE_ID, $this->root);

        // Новый код занял место старого.
        $this->assertSame('new app code', $this->read('app/new.php'), 'Новый код приложения должен быть установлен');
        $this->assertSame('new config', $this->read('config/app.php'), 'Конфигурация должна обновиться');
        $this->assertSame('new autoload', $this->read('vendor/autoload.php'), 'autoload должен обновиться');
        $this->assertSame('new public entry', $this->read('public/index.php'), 'Входная точка public должна обновиться');
        $this->assertSame("*\n!.gitignore\n", $this->read('bootstrap/cache/.gitignore'), 'Кэш должен собраться из staging');
        $this->assertSame('new artisan', $this->read('artisan'), 'Корневые файлы релиза должны обновляться');
        $this->assertSame('new front controller', $this->read('public_html/index.php'), 'Вход в витрину должен обновиться');
        $this->assertSame('User-agent: *', $this->read('public_html/robots.txt'), 'robots.txt входит в whitelist');
        $this->assertSame('new asset', $this->read('public_html/assets/app.js'), 'Ассеты витрины должны замениться');

        // Пользовательские файлы не тронуты, хотя в staging лежат их «новые»
        // версии.
        foreach ($protected as $path => $contents) {
            $this->assertSame(
                $contents,
                $this->read($path),
                "Файл {$path} не должен перезаписываться обновлением"
            );
        }
        // Старый код сохранён для отката.
        $backup = app(UpdateStorage::class)->backup(self::UPDATE_ID);
        $this->assertSame('old app code', (string) @file_get_contents($backup . '/app/old.php'), 'Старый код должен лежать в backup');
        $this->assertSame('old config', (string) @file_get_contents($backup . '/config/app.php'));
        $this->assertSame('old autoload', (string) @file_get_contents($backup . '/vendor/autoload.php'));
        $this->assertSame('old artisan', (string) @file_get_contents($backup . '/artisan'));
        $this->assertSame('old front controller', (string) @file_get_contents($backup . '/public_html/index.php'));
        $this->assertSame('old asset', (string) @file_get_contents($backup . '/public_html/assets/app.js'));

        // До снятия режима обслуживания сайт закрыт.
        $this->assertTrue(
            app(MaintenanceMode::class)->active(),
            'Во время подмены сайт должен быть в режиме обслуживания'
        );
    }

    public function test_rollback_restores_old_code_keeps_user_data_and_turns_off_maintenance(): void
    {
        $protected = $this->protectedSnapshot();
        $this->buildStaging();

        $applier = app(UpdateApplier::class);
        $applier->apply(self::UPDATE_ID, $this->root);
        $this->assertFileExists($this->root . '/app/new.php', 'Подмена должна произойти до отката');

        $applier->rollback(self::UPDATE_ID, $this->root);

        $this->assertSame('old app code', $this->read('app/old.php'), 'Старый код должен вернуться после отката');
        $this->assertSame('old config', $this->read('config/app.php'), 'Конфигурация должна вернуться');
        $this->assertSame('old autoload', $this->read('vendor/autoload.php'), 'autoload должен вернуться');
        $this->assertSame('old public entry', $this->read('public/index.php'));
        $this->assertSame('old artisan', $this->read('artisan'), 'Корневой artisan должен вернуться');
        $this->assertSame('old front controller', $this->read('public_html/index.php'));
        $this->assertSame('old asset', $this->read('public_html/assets/app.js'), 'Ассеты должны вернуться');

        $this->assertFileDoesNotExist($this->root . '/app/new.php', 'Код новой версии должен быть удалён');
        $this->assertFileDoesNotExist($this->root . '/public_html/robots.txt', 'Файлы, которых не было до обновления, должны исчезнуть');

        foreach ($protected as $path => $contents) {
            $this->assertSame($contents, $this->read($path), "{$path} должен остаться неизменным после отката");
        }

        $this->assertFalse(
            app(MaintenanceMode::class)->active(),
            'После отката режим обслуживания должен быть снят'
        );
    }

    /** Содержимое файлов, которые релиз не имеет права трогать. */
    private function protectedSnapshot(): array
    {
        return [
            '.env' => $this->read('.env'),
            'public_html/.htaccess' => $this->read('public_html/.htaccess'),
            'public_html/images/poster.jpg' => $this->read('public_html/images/poster.jpg'),
            'storage/app/public/x.txt' => $this->read('storage/app/public/x.txt'),
        ];
    }

    private function makeFixtureRoot(): void
    {
        foreach ([
            'app', 'config', 'vendor', 'public',
            'public_html/assets', 'public_html/images',
            'bootstrap/cache', 'storage/app/public',
        ] as $dir) {
            $this->makeDir($this->root . '/' . $dir);
        }

        $this->write('.env', "APP_ENV=fixture\nAPP_KEY=do-not-touch\n");
        $this->write('artisan', 'old artisan');
        $this->write('app/old.php', 'old app code');
        $this->write('config/app.php', 'old config');
        $this->write('vendor/autoload.php', 'old autoload');
        $this->write('public/index.php', 'old public entry');
        $this->write('public_html/index.php', 'old front controller');
        $this->write('public_html/.htaccess', 'RewriteRule ^ old-rule');
        $this->write('public_html/images/poster.jpg', 'old poster bytes');
        $this->write('public_html/assets/app.js', 'old asset');
        $this->write('storage/app/public/x.txt', 'user upload bytes');
    }

    private function buildStaging(): void
    {
        $staging = app(UpdateStorage::class)->staging(self::UPDATE_ID);

        foreach (['app', 'config', 'vendor', 'public', 'bootstrap/cache', 'public_html/assets', 'storage/app/public'] as $dir) {
            $this->makeDir($staging . '/' . $dir);
        }

        // Те же пути, что и в фикстуре, но с новым содержимым.
        $this->writeTo($staging, 'app/new.php', 'new app code');
        $this->writeTo($staging, 'config/app.php', 'new config');
        $this->writeTo($staging, 'vendor/autoload.php', 'new autoload');
        $this->writeTo($staging, 'public/index.php', 'new public entry');
        $this->writeTo($staging, 'bootstrap/cache/.gitignore', "*\n!.gitignore\n");
        $this->writeTo($staging, 'artisan', 'new artisan');
        $this->writeTo($staging, 'public_html/index.php', 'new front controller');
        $this->writeTo($staging, 'public_html/assets/app.js', 'new asset');
        $this->writeTo($staging, 'public_html/robots.txt', 'User-agent: *');

        // Защищённые пути тоже присутствуют в архиве — они обязаны проиграть.
        $this->writeTo($staging, '.env', 'STAGED ENV MUST NOT WIN');
        $this->writeTo($staging, 'public_html/.htaccess', 'staged htaccess');
        $this->writeTo($staging, 'public_html/images/poster.jpg', 'staged poster bytes');
        $this->writeTo($staging, 'storage/app/public/x.txt', 'staged user data');
    }

    private function read(string $relative): string
    {
        $contents = @file_get_contents($this->root . '/' . $relative);

        $this->assertNotFalse($contents, "Файл {$relative} должен существовать в фикстуре");

        return (string) $contents;
    }

    private function write(string $relative, string $contents): void
    {
        $this->writeTo($this->root, $relative, $contents);
    }

    private function writeTo(string $root, string $relative, string $contents): void
    {
        $path = $root . '/' . $relative;
        $this->makeDir(dirname($path));
        file_put_contents($path, $contents);
    }

    private function makeDir(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            $this->fail("Не удалось создать каталог {$path}");
        }
    }
}
