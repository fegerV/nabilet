<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Controllers;

use App\Modules\Tickets\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['order_id', 'session_id', 'user_id', 'status']);
        $perPage = (int) $request->get('per_page', 20);
        
        $query = Ticket::query()->with(['order', 'session', 'seat', 'inventoryItem']);
        
        if (isset($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }
        
        if (isset($filters['session_id'])) {
            $query->where('session_id', $filters['session_id']);
        }
        
        if (isset($filters['user_id'])) {
            $query->whereHas('order', fn($q) => $q->where('user_id', $filters['user_id']));
        }
        
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        $tickets = $query->paginate($perPage);
        
        return response()->json([
            'data' => $tickets,
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
                'last_page' => $tickets->lastPage(),
            ],
        ]);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        $ticket->load(['order', 'session', 'seat', 'inventoryItem', 'scans']);
        
        return response()->json(['data' => $ticket]);
    }

    public function qrCode(Ticket $ticket): JsonResponse
    {
        return response()->json([
            'data' => [
                'ticket_id' => $ticket->id,
                'qr_code' => $ticket->qr_code,
                'checkin_url' => route('tickets.checkin', $ticket),
            ],
        ]);
    }
}
