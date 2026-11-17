<?php

declare(strict_types=1);

namespace Nabilet\Modules\Installer\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Удаление диагностических файлов и legacy-установщика после развёртывания.
 *
 * ЗАЧЕМ. На шаред-хостинге в docroot рядом с приложением живут файлы, которые
 * не входят в релизный zip: `info.php` (с phpinfo()), иногда `install.php` или
 * каталог `install/` от старой версии мастера. Они открываются напрямую
 * (`https://домен/info.php`) и показывают конфигурацию PHP, переменные окружения
 * и пути — даже после того, как сам мастер установки закрыт `install.lock`.
 *
 * ПОЧЕМУ СПИСОК ЯВНЫЙ. Класс удаляет ТОЛЬКО перечисленные имена ТОЛЬКО в двух
 * известных корнях (корень проекта и публичный каталог). Обходные пути,
 * символические ссылки и произвольные каталоги не трогаются: «удалить всё
 * подозрительное» на боевом сайте рано или поздно удалит что-то нужное.
 *
 * ДВЕ ТОЧКИ ВЫЗОВА:
 *   1. `InstallerController::markAsInstalled()` — свежая установка;
 *   2. `InstallerServiceProvider::boot()` — уже установленный сайт после
 *      выкладки новой версии (зачистка один раз на версию приложения).
 */
final class SetupArtifactCleaner
{
    /** Файлы-диагностики и одиночные точки входа старого мастера. */
    private const FILE_NAMES = ['info.php', 'phpinfo.php', 'install.php'];

    /** Legacy-каталог мастера удаляем, только если внутри есть точка входа. */
    private const DIRECTORY_NAMES = ['install'];

    public function __construct(
        private readonly string $basePath,
        private readonly string $publicPath,
    ) {}

    public static function make(): self
    {
        return new self(base_path(), public_path());
    }

    /**
     * Метка «для этой версии уже убирали». Лежит в storage — туда пишет и
     * установщик, и приложение, поэтому запись доступна на шаред-хостинге.
     */
    public function markerPath(string $version): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $version) ?: 'unknown';

        return $this->basePath . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR
            . 'setup-purged-' . $safe . '.marker';
    }

    /**
     * Удалить известные артефакты. Возвращает список удалённых путей (относительно
     * корня проекта) — его видно в ответе POST /install и в журнале.
     *
     * @return list<string>
     */
    public function purge(): array
    {
        $removed = [];

        foreach ([$this->basePath, $this->publicPath] as $root) {
            if ($root === '' || ! is_dir($root)) {
                continue;
            }

            foreach (self::FILE_NAMES as $name) {
                $path = $root . DIRECTORY_SEPARATOR . $name;

                // Симлинк исключён: он может вести в системный каталог, и
                // удаление «файла» по ссылке задело бы чужой файл.
                if (is_link($path) || ! is_file($path)) {
                    continue;
                }

                try {
                    File::delete($path);
                    $removed[] = $this->displayPath($path);
                } catch (\Throwable $e) {
                    // Отсутствие прав не должно рушить уже завершённую установку:
                    // администратор увидит путь в логе и уберёт файл вручную.
                    Log::warning('Installer: не удалось удалить файл ' . $path, [
                        'reason' => $e->getMessage(),
                    ]);
                }
            }

            foreach (self::DIRECTORY_NAMES as $name) {
                $path = $root . DIRECTORY_SEPARATOR . $name;

                if (! is_dir($path) || is_link($path)) {
                    continue;
                }
                // Удаляем только то, что похоже на старый установщик. Каталог
                // `install/` с другим содержимым может быть частью сайта.
                if (! is_file($path . DIRECTORY_SEPARATOR . 'index.php')
                    && ! is_file($path . DIRECTORY_SEPARATOR . 'install.php')) {
                    continue;
                }

                try {
                    File::deleteDirectory($path);
                    $removed[] = $this->displayPath($path) . '/';
                } catch (\Throwable $e) {
                    Log::warning('Installer: не удалось удалить каталог ' . $path, [
                        'reason' => $e->getMessage(),
                    ]);
                }
            }
        }

        return array_values(array_unique($removed));
    }

    /**
     * Зачистка один раз на версию приложения: для уже установленного сайта,
     * куда просто залили новый zip (мастер при этом не запускается).
     *
     * @return list<string>
     */
    public function purgeOnce(string $version): array
    {
        $marker = $this->markerPath($version);

        if (is_file($marker)) {
            return [];
        }

        $removed = $this->purge();

        try {
            File::ensureDirectoryExists(dirname($marker));
            File::put($marker, date(DATE_ATOM));
        } catch (\Throwable $e) {
            Log::warning('Installer: не удалось записать метку зачистки ' . $marker, [
                'reason' => $e->getMessage(),
            ]);
        }

        return $removed;
    }

    private function displayPath(string $path): string
    {
        foreach ([$this->basePath, $this->publicPath] as $root) {
            $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($path, $prefix)) {
                return substr($path, strlen($prefix));
            }
        }

        return basename($path);
    }
}
