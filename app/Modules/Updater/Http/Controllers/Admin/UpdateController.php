<?php

declare(strict_types=1);

namespace Nabilet\Modules\Updater\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Updater\Models\SystemUpdate;
use Nabilet\Modules\Updater\Services\ArchiveDownloader;
use Nabilet\Modules\Updater\Services\UpdateOrchestrator;
use Nabilet\Modules\Updater\Services\UpdateStorage;

/**
 * Управление обновлением системы из админки.
 *
 * Контроллер сознательно тонкий: вся работа живёт в `UpdateOrchestrator` и
 * выполняется тиками крона, а не внутри HTTP-запроса. Здесь только валидация,
 * подтверждающая фраза, создание записи и чтение статуса.
 */
final class UpdateController extends Controller
{
    public function __construct(
        private readonly UpdateOrchestrator $orchestrator,
        private readonly UpdateStorage $storage,
        private readonly ArchiveDownloader $downloader,
    ) {
    }

    public function check(): JsonResponse
    {
        return response()->json(['data' => $this->orchestrator->check()]);
    }

    /**
     * Ручная загрузка архива: fallback для установок без ленты обновлений.
     * Хэш проверяется здесь же, чтобы битый файл не попал в рабочий каталог.
     */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:65536'],
            'checksum' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $data['file'];
        $checksum = strtolower((string) $data['checksum']);

        if (strtolower((string) $file->getClientOriginalExtension()) !== 'zip') {
            throw new ValidationError(['file' => ['Нужен ZIP-архив обновления.']]);
        }

        $real = $file->getRealPath();
        if ($real === false) {
            throw new ValidationError(['file' => ['Не удалось прочитать загруженный файл.']]);
        }

        $this->downloader->verifyHash($real, $checksum);
        $this->storage->ensureDirectories(0);

        $name = $checksum . '.zip';
        $target = $this->storage->uploads() . '/' . $name;
        if (! is_file($target)) {
            $file->move($this->storage->uploads(), $name);
        }
        if (! is_file($target)) {
            throw new ValidationError(['file' => ['Не удалось сохранить загруженный архив.']]);
        }

        return response()->json(['data' => [
            'file' => $name,
            'checksum' => $checksum,
            'size' => (int) filesize($target),
        ]]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:50', 'regex:/^\d+\.\d+\.\d+/'],
            'confirm_phrase' => ['required', 'string', 'max:32'],
            'source' => ['required', 'string', 'in:feed,upload'],
            'file' => ['required_if:source,upload', 'nullable', 'string', 'max:255'],
            'checksum' => ['required_if:source,upload', 'nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        if (strtoupper(trim((string) $data['confirm_phrase'])) !== 'UPDATE') {
            return $this->envelope('CONFIRMATION_REQUIRED', 'Для обновления введите UPDATE.', 422);
        }

        $update = $this->orchestrator->start([
            'version' => (string) $data['version'],
            'source' => (string) $data['source'],
            'file' => isset($data['file']) ? (string) $data['file'] : null,
            'checksum' => isset($data['checksum']) ? (string) $data['checksum'] : null,
        ], (int) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => [
            'update_id' => (int) $update->id,
            'status' => (string) $update->status,
            'message' => 'Обновление поставлено в очередь планировщика.',
        ]], 202);
    }

    public function status(): JsonResponse
    {
        /** @var SystemUpdate|null $update */
        $update = SystemUpdate::query()->orderByDesc('id')->first();

        if ($update === null) {
            return response()->json(['data' => [
                'status' => 'idle',
                'status_label' => 'Обновления не выполнялись',
                'can_rollback' => false,
            ]]);
        }

        return response()->json(['data' => $update->toAdminArray()]);
    }

    public function cancel(): JsonResponse
    {
        /** @var SystemUpdate|null $update */
        $update = SystemUpdate::query()->orderByDesc('id')->first();
        if ($update === null) {
            return $this->envelope('UPDATE_NOT_FOUND', 'Активного обновления нет.', 404);
        }

        $this->orchestrator->cancel((int) $update->id);

        return response()->json(['data' => ['success' => true]]);
    }

    public function rollback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirm_phrase' => ['required', 'string', 'max:32'],
        ]);
        if (strtoupper(trim((string) $data['confirm_phrase'])) !== 'ROLLBACK') {
            return $this->envelope('CONFIRMATION_REQUIRED', 'Для отката введите ROLLBACK.', 422);
        }

        /** @var SystemUpdate|null $update */
        $update = SystemUpdate::query()->orderByDesc('id')->first();
        if ($update === null) {
            return $this->envelope('UPDATE_NOT_FOUND', 'Обновлений не было.', 404);
        }

        $result = $this->orchestrator->requestRollback((int) $update->id);

        return response()->json(['data' => [
            'update_id' => (int) $result->id,
            'status' => (string) $result->status,
        ]], 202);
    }

    public function history(): JsonResponse
    {
        return response()->json($this->orchestrator->history());
    }

    private function envelope(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => [],
            ],
        ], $status);
    }
}
