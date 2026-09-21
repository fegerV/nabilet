<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Http\Controllers;

use Nabilet\Modules\Events\Http\Requests\StoreEventRequest;
use Nabilet\Modules\Events\Http\Requests\UpdateEventRequest;
use Nabilet\Modules\Events\Http\Resources\EventResource;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Events\Services\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class EventController extends Controller
{
    public function __construct(
        private readonly EventService $eventService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['organization_id', 'category_id', 'status', 'is_featured']);
        $perPage = (int) $request->get('per_page', 20);
        
        $events = $this->eventService->paginate($filters, $perPage);
        
        return response()->json([
            'data' => EventResource::collection($events),
            'meta' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
            ],
        ]);
    }

    public function show(Event $event): JsonResponse
    {
        $event->load(['category', 'translations', 'sessions']);
        
        return response()->json([
            'data' => new EventResource($event),
        ]);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $data = $request->validated();
        $event = $this->eventService->create($data);
        
        return response()->json([
            'data' => new EventResource($event->fresh()),
        ], 201);
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $data = $request->validated();
        $event = $this->eventService->update($event, $data);
        
        return response()->json([
            'data' => new EventResource($event->fresh()),
        ]);
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->eventService->delete($event);
        
        return response()->json(null, 204);
    }
}
