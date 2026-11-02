<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Organizations\Repositories;

use Nabilet\Modules\Core\Organizations\Models\Organization;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OrganizationRepository
{
    public function __construct(
        protected Organization $model
    ) {}

    public function find(int $id): ?Organization
    {
        return $this->model->with(['owner', 'members'])->find($id);
    }

    public function findByPublicId(string $publicId): ?Organization
    {
        return $this->model->where('public_id', $publicId)->first();
    }

    public function findBySlug(string $slug, ?int $excludeId = null): ?Organization
    {
        $query = $this->model->where('slug', $slug);

        // Второй аргумент — не скоуп, а «исключить эту организацию»: проверка
        // уникальности slug при переименовании. Имя прежнее отвечало «арендатор
        // выборки», которым он не является (organizations — глобальная таблица,
        // класс C).
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->first();
    }

    /**
     * Возвращает пагинатор, а не `Collection` — так объявлено раньше и это было
     * неправдой: `paginate($limit)` отдаёт `LengthAwarePaginator`. Тип исправлен,
     * чтобы объявление совпадало с фактом (см. `OrganizationService::all()`).
     */
    public function all(int $limit = 15): LengthAwarePaginator
    {
        return $this->model->with(['owner'])->paginate($limit);
    }

    public function create(array $data): Organization
    {
        return $this->model->create($data);
    }

    public function update(Organization $organization, array $data): Organization
    {
        $organization->update($data);
        return $organization->fresh();
    }

    public function delete(Organization $organization): bool
    {
        return $organization->delete();
    }

    public function getMemberCount(Organization $organization): int
    {
        return $organization->members()->count();
    }
}
