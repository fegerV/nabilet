<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Http\Controllers;

use Illuminate\Routing\Controller;
use Nabilet\Modules\Venues\Halls\Services\HallService;
use Nabilet\Modules\Venues\Halls\Http\Resources\HallResource;
use Nabilet\Modules\Venues\Halls\Http\Resources\HallCollection;
use Nabilet\Modules\Venues\Halls\Http\Resources\SchemaVersionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HallController extends Controller
{
    public function __construct(
        protected HallService $service
    ) {}

    public function index(int $venueId, Request $request): HallCollection
    {
        $limit = (int) $request->get('limit', 15);
        $halls = $this->service->findByVenue($venueId, $limit);

        return new HallCollection($halls);
    }

    public function show(string $publicId): HallResource
    {
        $hall = $this->service->findByPublicId($publicId);

        if (!$hall) {
            abort(404, 'Hall not found');
        }

        return new HallResource($hall);
    }

    public function store(Request $request): HallResource
    {
        $validated = $request->validate([
            'venue_id' => ['required', 'integer', 'exists:venues,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $hall = $this->service->createHall($validated);

        return new HallResource($hall);
    }

    public function update(Request $request, string $publicId): HallResource
    {
        $hall = $this->service->findByPublicId($publicId);

        if (!$hall) {
            abort(404, 'Hall not found');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $hall = $this->service->updateHall($hall, $validated);

        return new HallResource($hall);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $hall = $this->service->findByPublicId($publicId);

        if (!$hall) {
            abort(404, 'Hall not found');
        }

        $this->service->deleteHall($hall);

        return response()->json(['message' => 'Hall deleted successfully']);
    }

    public function getSchemaVersions(string $publicId): JsonResponse
    {
        $hall = $this->service->findByPublicId($publicId);

        if (!$hall) {
            abort(404, 'Hall not found');
        }

        $versions = $this->service->getSchemaVersions($hall);

        return response()->json([
            'data' => SchemaVersionResource::collection($versions),
        ]);
    }

    public function createSchemaDraft(Request $request, string $publicId): SchemaVersionResource
    {
        $hall = $this->service->findByPublicId($publicId);

        if (!$hall) {
            abort(404, 'Hall not found');
        }

        $validated = $request->validate([
            'payload' => ['required', 'array'],
        ]);

        $version = $this->service->createSchemaDraft($hall, $validated['payload'], $request->user()->id);

        return new SchemaVersionResource($version);
    }

    public function publishSchemaVersion(Request $request, int $versionId): SchemaVersionResource
    {
        $version = $this->service->publishSchemaVersion($versionId, $request->user()->id);

        return new SchemaVersionResource($version);
    }
}
