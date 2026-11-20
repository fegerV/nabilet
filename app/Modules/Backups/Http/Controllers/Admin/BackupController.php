<?php

declare(strict_types=1);

namespace Nabilet\Modules\Backups\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Backups\Jobs\RunBackupJob;
use Nabilet\Modules\Backups\Services\YandexDiskBackupService;
use Nabilet\Modules\Backups\Services\YandexDiskSettings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ручное скачивание локальных резервных копий (админка, ТЗ §72).
 *
 * Локальные копии живут в `storage/app/backups` — ровно там, где их создаёт
 * `YandexDiskBackupService::createDatabaseBackup()`/`createFullBackup()`. Чтобы
 * файл можно было скачать, бэкап должен быть создан с `--keep-local` (иначе после
 * выгрузки на Яндекс.Диск локальный файл удаляется).
 *
 * Защита:
 *   - маршруты под `['auth:api', 'admin']` — только сотрудник с ролью admin;
 *   - имя файла строго валидируется (`basename` + строгий regex) — обход пути
 *     (`../`, абсолютный путь) невозможен;
 *   - несуществующий файл — 404 (неотличимо от отсутствующего).
 */
final class BackupController extends Controller
{
    private const DIR = 'backups';

    public function __construct(
        private readonly YandexDiskSettings $settings,
        private readonly YandexDiskBackupService $disk,
    ) {}

    /**
     * Список локальных бэкапов (имя, размер, дата), от новых к старым.
     */
    public function index(): JsonResponse
    {
        $dir = storage_path('app/' . self::DIR);

        if (! is_dir($dir)) {
            return response()->json(['data' => []]);
        }

        $files = glob($dir . '/*') ?: [];

        $data = [];

        foreach ($files as $path) {
            if (! is_file($path)) {
                continue;
            }

            $data[] = [
                'name' => basename($path),
                'size' => filesize($path),
                'modified_at' => filemtime($path),
            ];
        }

        usort($data, static fn (array $a, array $b): int => $b['modified_at'] <=> $a['modified_at']);

        return response()->json(['data' => $data]);
    }

    /**
     * Скачивание конкретного файла.
     */
    public function download(string $file): BinaryFileResponse
    {
        $safe = $this->safeName($file);

        $full = storage_path('app/' . self::DIR . '/' . $safe);

        if (! is_file($full)) {
            throw new NotFoundError('Backup', $file);
        }

        return response()->download($full, $safe);
    }

    /** Состояние подключения к Диску и последнего запуска. */
    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    /** Сохранить ClientID приложения Яндекс OAuth. */
    public function saveClientId(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string', 'max:255'],
        ]);
        $this->settings->saveClientId((string) $data['client_id']);

        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    /**
     * Принять токен из OAuth-колбэка (fragment обработал браузер).
     *
     * Токен шифруется и больше НЕ возвращается наружу — только признак
     * «подключён». Никогда не пишем его в логи и в ответ.
     */
    public function saveToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:2048'],
            'client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'backups_path' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (isset($data['client_id'])) {
            $this->settings->saveClientId((string) $data['client_id']);
        }
        if (isset($data['backups_path'])) {
            $this->settings->saveBackupsPath((string) $data['backups_path']);
        }
        $this->settings->saveToken((string) $data['access_token']);

        \Illuminate\Support\Facades\Log::channel('security')->info('Yandex Disk connected', [
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    public function disconnect(): JsonResponse
    {
        $this->settings->forgetToken();

        return response()->json(['data' => $this->settings->forAdmin()]);
    }

    /** Проверить токен дешёвым запросом к API Диска. */
    public function testConnection(): JsonResponse
    {
        $result = $this->disk->testConnection();

        return response()->json(['data' => $result], $result['ok'] ? 200 : 422);
    }

    /** Резервные копии на Диске (только имена — скачивание через Диск). */
    public function remote(): JsonResponse
    {
        try {
            return response()->json(['data' => $this->disk->listRemoteBackups()]);
        } catch (\Throwable $e) {
            throw new ValidationError(['disk' => [$e->getMessage()]]);
        }
    }

    /**
     * Поставить бэкап в очередь. Режим db | files | full.
     *
     * Сам mysqldump НЕ запускается здесь: результат приходит в статусе
     * последнего запуска, который пишет RunBackupJob.
     */
    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:db,files,full'],
        ]);
        $mode = (string) $data['mode'];

        if (! $this->settings->isConnected()) {
            throw new ValidationError(['disk' => ['Сначала подключите Яндекс.Диск.']]);
        }

        RunBackupJob::dispatch($mode, (int) $request->user()->getAuthIdentifier());
        $this->settings->recordRun($mode, 'queued', 'Задача поставлена в очередь.');

        \Illuminate\Support\Facades\Log::channel('security')->info('Backup queued', [
            'mode' => $mode,
            'user_id' => $request->user()->getAuthIdentifier(),
        ]);

        return response()->json(['data' => [
            'queued' => true,
            'mode' => $mode,
            'message' => 'Бэкап поставлен в очередь. Результат появится в статусе последнего запуска.',
        ]], 202);
    }

    /**
     * Допустимое имя файла: никакой навигации по директориям.
     */
    private function safeName(string $file): string
    {
        $base = basename($file);

        if ($base !== $file || preg_match('/^[A-Za-z0-9._-]+$/', $file) !== 1) {
            throw new NotFoundError('Backup', $file);
        }

        return $base;
    }
}
