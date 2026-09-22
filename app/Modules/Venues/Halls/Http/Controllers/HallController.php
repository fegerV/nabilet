<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Http\Controllers;

use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Venues\Halls\Models\Hall;
use Nabilet\Modules\Venues\Halls\Services\HallService;
use Nabilet\Modules\Venues\Halls\Http\Resources\HallResource;
use Nabilet\Modules\Venues\Halls\Http\Resources\HallCollection;
use Nabilet\Modules\Venues\Halls\Http\Resources\SchemaVersionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hall and hall-schema endpoints.
 *
 * The five `abort(404, 'Hall not found')` calls this class used to contain are gone.
 * `abort()` hands the failure to Laravel's default renderer, which answers with
 * `{"message":"Hall not found","exception":"NotFoundHttpException","trace":[…]}`
 * — verified live. That is neither the §66 envelope nor safe in production. A
 * `NotFoundError` carries `HALL_NOT_FOUND` plus the public id, and travels through
 * `ApiExceptionRenderer` like every other failure.
 */
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
        return new HallResource($this->findOrFail($publicId));
    }

    public function store(Request $request): HallResource
    {
        $validated = $request->validate([
            // `bail`/`integer` guard the BIGINT cast: `venues.id` is BIGINT, so a
            // non-numeric `venue_id` would raise SQLSTATE[22P02] and answer 500 instead
            // of 422. See `CartController::addItem()` for the full explanation.
            'venue_id' => ['bail', 'required', 'integer', 'exists:venues,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $hall = $this->service->createHall($validated);

        return new HallResource($hall);
    }

    public function update(Request $request, string $publicId): HallResource
    {
        $hall = $this->findOrFail($publicId);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $hall = $this->service->updateHall($hall, $validated);

        return new HallResource($hall);
    }

    public function destroy(string $publicId): JsonResponse
    {
        $this->service->deleteHall($this->findOrFail($publicId));

        return response()->json(['message' => 'Hall deleted successfully']);
    }

    public function getSchemaVersions(string $publicId): JsonResponse
    {
        $hall = $this->findOrFail($publicId);

        $versions = $this->service->getSchemaVersions($hall);

        return response()->json([
            'data' => SchemaVersionResource::collection($versions),
        ]);
    }

    public function createSchemaDraft(Request $request, string $publicId): SchemaVersionResource
    {
        $hall = $this->findOrFail($publicId);

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

    /**
     * Resolve a hall by public id or fail with the envelope's 404.
     *
     * @throws NotFoundError
     */
    private function findOrFail(string $publicId): Hall
    {
        $hall = $this->service->findByPublicId($publicId);

        if (!$hall) {
            throw new NotFoundError('Hall', $publicId);
        }

        return $hall;
    }
}
