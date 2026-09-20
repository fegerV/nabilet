<?php

declare(strict_types=1);

namespace App\Modules\Core\Organizations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Organizations\Services\OrganizationService;
use App\Modules\Core\Organizations\Http\Requests\StoreOrganizationRequest;
use App\Modules\Core\Organizations\Http\Requests\UpdateOrganizationRequest;
use App\Modules\Core\Organizations\Http\Resources\OrganizationResource;
use App\Modules\Core\Organizations\Http\Resources\OrganizationCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function __construct(
        protected OrganizationService $service
    ) {}

    public function index(Request $request): OrganizationCollection
    {
        $limit = (int) $request->get('limit', 15);
        $organizations = $this->service->repository->all($limit);

        return new OrganizationCollection($organizations);
    }

    public function show(string $publicId): OrganizationResource
    {
        $organization = $this->service->repository->findByPublicId($publicId);

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        return new OrganizationResource($organization);
    }

    public function store(StoreOrganizationRequest $request): OrganizationResource
    {
        $organization = $this->service->createOrganization(
            $request->validated(),
            $request->user()
        );

        return new OrganizationResource($organization);
    }

    public function update(UpdateOrganizationRequest $request, string $publicId): OrganizationResource
    {
        $organization = $this->service->repository->findByPublicId($publicId);

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        $organization = $this->service->updateOrganization($organization, $request->validated());

        return new OrganizationResource($organization);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $organization = $this->service->repository->findByPublicId($publicId);

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        $this->service->deleteOrganization($organization);

        return response()->json(['message' => 'Organization deleted successfully']);
    }

    public function addMember(StoreOrganizationRequest $request, string $publicId): JsonResponse
    {
        $organization = $this->service->repository->findByPublicId($publicId);

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        // TODO: Implement member addition logic

        return response()->json(['message' => 'Member added successfully']);
    }

    public function removeMember(string $publicId, int $userId): JsonResponse
    {
        $organization = $this->service->repository->findByPublicId($publicId);

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        // TODO: Implement member removal logic

        return response()->json(['message' => 'Member removed successfully']);
    }
}
