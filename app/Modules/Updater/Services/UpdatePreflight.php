<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nabilet\Modules\Backups\Services\YandexDiskSettings;
use Nabilet\Modules\System\Services\HostChecks;
use Nabilet\Modules\Updater\Domain\ReleaseManifest;

/**
 * Блокирующие проверки перед скачиванием и подменой файлов.
 *
 * Возвращает структуру, а не бросает исключение: причина отказа должна быть
 * видна на экране обновления целиком (диск, cron, бэкап, миграции), а не по
 * одному сообщению за раз. Ответственность за «что делать дальше» — у оркестратора.
 */
final class UpdatePreflight
{
    public function __construct(
        private readonly HostChecks $host,
        private readonly ManifestVerifier $verifier,
        private readonly UpdateStorage $storage,
        private readonly YandexDiskSettings $backups,
    ) {
    }

    /** @return array{ok: bool, problems: list<string>, warnings: list<string>, backup: array<string, mixed>} */
    public function check(ReleaseManifest $manifest): array
    {
        $problems = [];
        $warnings = [];

        if (! is_file(storage_path('install.lock'))) {
            $problems[] = 'Система не установлена: отсутствует storage/install.lock.';
        }

        $current = (string) config('nabilet.version', '0.0.0');
        if (! $manifest->appliesTo($current)) {
            $problems[] = sprintf('Релиз %s не является обновлением для версии %s.', $manifest->version, $current);
        }

        try {
            $this->verifier->verifyPlatform($manifest);
        } catch (\Nabilet\Core\Errors\AppError $e) {
            $problems[] = $e->getMessage();
        }

        $disk = $this->host->diskSpace();
        if ((string) $disk['status'] === 'error') {
            $problems[] = 'Не удалось определить свободное место на диске.';
        } elseif (isset($disk['free_gb']) && $disk['free_gb'] * 1024 * 1024 * 1024 < 3 * $manifest->sizeBytes) {
            $problems[] = sprintf(
                'Нужно не менее %.1f МБ свободного места (zip + staging + бэкап).',
                round(3 * $manifest->sizeBytes / 1024 / 1024, 1),
            );
        } elseif ((string) $disk['status'] === 'warning') {
            $warnings[] = 'Диск заполнен более чем на 80 % — после обновления места станет меньше.';
        }

        foreach ([base_path(), storage_path(), base_path('bootstrap/cache'), base_path('public_html')] as $dir) {
            if (is_dir($dir) && ! is_writable($dir)) {
                $problems[] = 'Нет прав на запись: ' . $dir;
            }
        }

        if (! $this->storage->heartbeatFresh()) {
            $problems[] = 'Планировщик не запускался больше трёх минут. Проверьте cron с artisan schedule:run.';
        }

        $pending = $this->pendingMigrations();
        if ($pending !== []) {
            $problems[] = 'Есть неприменённые миграции от предыдущей попытки: ' . implode(', ', array_slice($pending, 0, 3));
        }

        $backup = $this->backupState();
        if ((bool) config('updates.require_backup', true) && ! $backup['fresh']) {
            if (! $backup['disk_connected'] && ! (bool) config('updates.allow_local_backup', true)) {
                $problems[] = 'Нет свежей полной копии, а локальные бэкапы запрещены настройками.';
            } elseif (! $backup['disk_connected']) {
                $warnings[] = 'Будет создан локальный полный бэкап (без выгрузки на Яндекс.Диск).';
            } else {
                $warnings[] = 'Будет создана полная резервная копия перед обновлением.';
            }
        }

        return [
            'ok' => $problems === [],
            'problems' => $problems,
            'warnings' => $warnings,
            'backup' => $backup,
        ];
    }

    /** @return array{fresh: bool, needs_run: bool, disk_connected: bool, detail: string} */
    public function backupState(): array
    {
        $last = $this->host->lastBackup();
        $localFresh = $this->hasFreshLocalFullBackup();

        // Штатный `backup:run` после выгрузки удаляет локальный файл, поэтому
        // одного HostChecks мало: успешная полная копия на Диске тоже считается
        // свежей. Ключ времени в forAdmin() — `at`, а не `last_run_at`.
        $remote = $this->backups->forAdmin()['last_run'] ?? [];
        $remoteAt = isset($remote['at']) ? strtotime((string) $remote['at']) : false;
        $remoteFresh = ($remote['status'] ?? '') === 'success'
            && ($remote['mode'] ?? '') === 'full'
            && $remoteAt !== false
            && $remoteAt >= time() - 26 * 3600;
        $fresh = $localFresh || $remoteFresh;

        return [
            'fresh' => $fresh,
            'needs_run' => ! $fresh && (bool) config('updates.require_backup', true),
            'disk_connected' => $this->backups->isConnected(),
            'detail' => isset($last['message']) ? (string) $last['message'] : (string) ($last['last_backup'] ?? ''),
        ];
    }

    /** Свежий полный архив именно в локальном каталоге бэкапов. */
    public function hasFreshLocalFullBackup(): bool
    {
        $dir = storage_path('app/backups');
        $threshold = time() - 26 * 3600;

        foreach (['full_*.zip', 'full_*.zip.enc'] as $pattern) {
            foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime >= $threshold && (int) filesize($file) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function pendingMigrations(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [];
        }

        $ran = DB::table('migrations')->pluck('migration')->all();
        $ran = array_map('strval', $ran);
        $pending = [];

        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $name = basename($path, '.php');
            if (! in_array($name, $ran, true)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }
}
