<?php

declare(strict_types=1);

namespace Nabilet\Modules\Media\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Core\Support\PublicId;
use Nabilet\Modules\Media\Models\MediaAsset;
use Nabilet\Modules\Media\Models\MediaFolder;

/**
 * Виртуальные папки медиатеки.
 *
 * Папка не создаёт каталог на диске и не меняет путь файла — она только
 * группирует записи `media_assets.folder_id`. Поэтому перемещение файла между
 * папками не ломает уже выданные ссылки на афиши и галереи.
 */
final class MediaFolderController extends Controller
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function index(): JsonResponse
    {
        $organizationId = $this->context->idOrNull();
        if ($organizationId === null) {
            return response()->json(['data' => []]);
        }

        $folders = $this->tenant(fn () => MediaFolder::query()
            ->where('organization_id', $organizationId)
            ->withCount('assets')
            ->orderBy('parent_id')
            ->orderBy('name')
            ->get())
            ->map(fn (MediaFolder $f): array => [
                'id' => (int) $f->id,
                'public_id' => (string) $f->public_id,
                'parent_id' => (int) $f->parent_id,
                'name' => (string) $f->name,
                'files_count' => (int) $f->assets_count,
            ])
            ->all();

        return response()->json(['data' => $folders]);
    }

    public function store(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $parentId = (int) ($data['parent_id'] ?? 0);
        if ($parentId !== 0) {
            $this->find($parentId, $organizationId);
        }
        $this->assertNameFree($organizationId, $parentId, trim((string) $data['name']));

        $folder = $this->tenant(fn () => MediaFolder::query()->create([
            'public_id' => PublicId::generate(),
            'organization_id' => $organizationId,
            'parent_id' => $parentId,
            'name' => trim((string) $data['name']),
        ]));

        return response()->json(['data' => $this->present($folder)], 201);
    }

    public function update(Request $request, int $folder): JsonResponse
    {
        $organizationId = $this->organizationId();
        $model = $this->find($folder, $organizationId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim((string) $data['name']);
        $this->assertNameFree($organizationId, (int) $model->parent_id, $name, (int) $model->id);

        $model->update(['name' => $name]);

        return response()->json(['data' => $this->present($model->fresh() ?? $model)]);
    }

    public function destroy(int $folder): JsonResponse
    {
        $organizationId = $this->organizationId();
        $model = $this->find($folder, $organizationId);

        if ($model->children()->exists()) {
            throw new ValidationError(['folder' => ['Сначала удалите вложенные папки.']]);
        }
        if ($model->assets()->exists()) {
            throw new ValidationError(['folder' => ['Папка не пуста: переместите или удалите файлы.']]);
        }
        $model->delete();

        return response()->json(null, 204);
    }

    /** Переместить несколько файлов в папку (0 — корень). */
    public function moveFiles(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId();
        $data = $request->validate([
            'media_ids' => ['required', 'array', 'min:1', 'max:200'],
            'media_ids.*' => ['integer', 'min:1'],
            'folder_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $folderId = (int) ($data['folder_id'] ?? 0);
        if ($folderId !== 0) {
            $this->find($folderId, $organizationId);
        }
        $ids = array_values(array_unique(array_map('intval', $data['media_ids'])));

        $moved = $this->tenant(function () use ($ids, $organizationId, $folderId): int {
            return MediaAsset::query()
                ->whereIn('id', $ids)
                ->where(function ($q) use ($organizationId): void {
                    $q->where('organization_id', $organizationId)->orWhereNull('organization_id');
                })
                ->update(['folder_id' => $folderId === 0 ? null : $folderId]);
        });

        return response()->json(['data' => ['moved' => $moved, 'folder_id' => $folderId === 0 ? null : $folderId]]);
    }

    private function organizationId(): int
    {
        $id = $this->context->idOrNull();
        if ($id === null) {
            throw new ValidationError(['organization_id' => ['Нет организации для управления файлами.']]);
        }

        return $id;
    }

    private function find(int $id, int $organizationId): MediaFolder
    {
        $folder = $this->tenant(fn () => MediaFolder::query()
            ->where('id', $id)
            ->where('organization_id', $organizationId)
            ->first());

        if ($folder === null) {
            throw new NotFoundError('MediaFolder', (string) $id);
        }

        return $folder;
    }

    private function assertNameFree(int $organizationId, int $parentId, string $name, ?int $ignoreId = null): void
    {
        $query = $this->tenant(fn () => MediaFolder::query()
            ->where('organization_id', $organizationId)
            ->where('parent_id', $parentId)
            ->where('name', $name));
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw new ValidationError(['name' => ['В этой папке уже есть папка с таким названием.']]);
        }
    }

    /** @return array<string, mixed> */
    private function present(MediaFolder $folder): array
    {
        return [
            'id' => (int) $folder->id,
            'public_id' => (string) $folder->public_id,
            'parent_id' => (int) $folder->parent_id,
            'name' => (string) $folder->name,
        ];
    }

    /** @template T @param callable():T $cb @return T */
    private function tenant(callable $cb): mixed
    {
        return app(\Nabilet\Core\Tenancy\OrganizationContext::class)->withoutScope($cb);
    }
}
