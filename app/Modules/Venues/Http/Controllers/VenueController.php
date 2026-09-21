<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Http\Controllers;

use Nabilet\Modules\Venues\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['organization_id', 'city']);
        $perPage = (int) $request->get('per_page', 20);
        
        $query = Venue::query()->with(['halls', 'organization']);
        
        if (isset($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }
        
        if (isset($filters['city'])) {
            $query->where('city', 'like', '%' . $filters['city'] . '%');
        }
        
        $venues = $query->paginate($perPage);
        
        return response()->json([
            'data' => $venues,
            'meta' => [
                'current_page' => $venues->currentPage(),
                'per_page' => $venues->perPage(),
                'total' => $venues->total(),
                'last_page' => $venues->lastPage(),
            ],
        ]);
    }

    public function show(Venue $venue): JsonResponse
    {
        $venue->load(['halls', 'organization', 'translations']);
        
        return response()->json(['data' => $venue]);
    }
}
