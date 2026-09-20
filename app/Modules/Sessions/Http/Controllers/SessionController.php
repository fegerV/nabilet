<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Http\Controllers;

use App\Modules\Sessions\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['event_id', 'hall_id', 'status']);
        $perPage = (int) $request->get('per_page', 20);
        
        $query = Session::query()->with(['event', 'hall', 'schemaVersion']);
        
        if (isset($filters['event_id'])) {
            $query->where('event_id', $filters['event_id']);
        }
        
        if (isset($filters['hall_id'])) {
            $query->where('hall_id', $filters['hall_id']);
        }
        
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        $sessions = $query->paginate($perPage);
        
        return response()->json([
            'data' => $sessions,
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
                'last_page' => $sessions->lastPage(),
            ],
        ]);
    }

    public function show(Session $session): JsonResponse
    {
        $session->load(['event', 'hall', 'schemaVersion', 'inventoryItems']);
        
        return response()->json(['data' => $session]);
    }
}
