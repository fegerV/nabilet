<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Http\Controllers;

use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Venues\Models\Hall;
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

    /**
     * Все залы (для формы сеанса в админке).
     */
    public function indexAll(): HallCollection
    {
        return new HallCollection($this->service->findAll());
    }

    public function show(string $publicId): HallResource
    {
        return new HallResource($this->findOrFail($publicId));
    }

    public function store(Request $request): HallResource
    {
        $validated = $request->validate([
            // `bail`/`integer` guard the BIGINT lookup: MySQL does not reject a
            // comparison against a string, it coerces it, so `exists` can match
            // `'1abc'` to row 1. See `CartController::addItem()` for the measurements.
            'venue_id' => ['bail', 'required', 'integer', 'exists:venues,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'width' => ['nullable', 'integer', 'min:0'],
            'height' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        // БД требует status NOT NULL.
        $validated['status'] ??= 'active';

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

    /**
     * Публичная версия схемы для витрины (B10).
     *
     * Роут `/halls/{publicId}` открыт, но его ресурс не отдаёт geometry-схему,
     * а список версий скрыт под admin-middleware — витрине (`/#/event/:slug/seats`)
     * нечем рисовать посадку. Отдаём ОДНУ опубликованную версию и только те
     * поля, что нужны для рендера; черновики наружу не светятся.
     */
    public function publishedSchema(string $publicId): JsonResponse
    {
        $hall = $this->findOrFail($publicId);

        $version = $this->service->getPublishedSchemaVersion($hall);

        if ($version === null) {
            throw new NotFoundError('PublishedHallSchema', $publicId);
        }

        return response()->json([
            'data' => [
                'id' => $version->id,
                'public_id' => $version->public_id,
                'hall_id' => $version->hall_id,
                'version' => $version->version,
                'status' => $version->status,
                'width' => $version->width,
                'height' => $version->height,
                'background_url' => $version->background_url,
                'schema' => $version->schema_json,
                'published_at' => $version->published_at?->toIso8601String(),
            ],
        ]);
    }

    public function createSchemaDraft(Request $request, string $publicId): SchemaVersionResource
    {
        $hall = $this->findOrFail($publicId);

        $validated = $request->validate([
            'payload' => ['required', 'array'],
            // B6: клиент может прислать id версии, которую считает черновиком.
            // Сервер проверяет принадлежность залу и статус draft; для
            // published/чужой — 409/404 в конверте §66 (см. HallService).
            'version_id' => ['nullable', 'integer'],
        ]);

        $version = $this->service->createSchemaDraft(
            $hall,
            $validated['payload'],
            $request->user()->id,
            isset($validated['version_id']) ? (int) $validated['version_id'] : null,
        );

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
