<?php

declare(strict_types=1);

namespace Nabilet\Modules\Backups\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\NotFoundError;
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
