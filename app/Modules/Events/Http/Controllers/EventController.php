<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Http\Resources\EventResource;
use App\Modules\Events\Http\Resources\EventCollection;
use App\Modules\Events\Http\Requests\ListEventsRequest;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    /**
     * List public events with filtering and pagination
     */
    public function index(ListEventsRequest $request): JsonResponse
    {
        $query = Event::with(['organization', 'category', 'translations'])
            ->where('is_published', true);

        // Apply filters
        if ($search = $request->input('q')) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhereHas('translations', fn($t) => 
                      $t->where('title', 'LIKE', "%{$search}%"));
            });
        }

        if ($city = $request->input('city')) {
            $query->where('city', $city);
        }

        if ($category = $request->input('category')) {
            $query->where('category_id', $category);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('starts_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('starts_at', '<=', $dateTo);
        }

        if ($priceFrom = $request->input('price_from')) {
            $query->where('min_price', '>=', (int)$priceFrom);
        }

        if ($priceTo = $request->input('price_to')) {
            $query->where('max_price', '<=', (int)$priceTo);
        }

        if ($availability = $request->input('availability')) {
            $query->where('available_tickets', '>', 0);
        }

        $events = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => EventCollection::make($events->items()),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ]
        ]);
    }

    /**
     * Get single event by public_id or slug
     */
    public function show(string $identifier): JsonResponse
    {
        $event = Event::with(['organization', 'category', 'translations', 'sessions'])
            ->where(function($q) use ($identifier) {
                $q->where('public_id', $identifier)
                  ->orWhere('slug', $identifier);
            })
            ->firstOrFail();

        return response()->json([
            'data' => new EventResource($event)
        ]);
    }
}
