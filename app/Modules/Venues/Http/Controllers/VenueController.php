<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Http\Controllers;

use Nabilet\Modules\Venues\Models\Venue;
use Nabilet\Modules\Venues\Models\HallSchema;
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
        $venue->load(['halls', 'organization', 'translations', 'hallSchemas']);
        
        return response()->json(['data' => $venue]);
    }

    /**
     * Create a new hall schema for a venue
     */
    public function storeSchema(Request $request, Venue $venue): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'schema' => 'required|array',
            'schema.rows' => 'required|array',
        ]);

        $hallSchema = HallSchema::create([
            'venue_id' => $venue->id,
            'name' => $request->name,
            'schema' => $request->schema,
            'is_active' => true,
        ]);

        $hallSchema->load('venue');

        return response()->json([
            'success' => true,
            'data' => $hallSchema,
        ], 201);
    }

    /**
     * Update an existing hall schema
     */
    public function updateSchema(Request $request, HallSchema $hallSchema): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'schema' => 'sometimes|required|array',
            'schema.rows' => 'sometimes|required|array',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($request->has('name')) {
            $hallSchema->name = $request->name;
        }

        if ($request->has('schema')) {
            $hallSchema->schema = $request->schema;
        }

        if ($request->has('is_active')) {
            $hallSchema->is_active = $request->is_active;
        }

        $hallSchema->save();

        $hallSchema->load('venue');

        return response()->json([
            'success' => true,
            'data' => $hallSchema,
        ]);
    }

    /**
     * Delete a hall schema
     */
    public function deleteSchema(HallSchema $hallSchema): JsonResponse
    {
        $hallSchema->delete();

        return response()->json([
            'success' => true,
            'message' => 'Hall schema deleted successfully',
        ]);
    }
}
