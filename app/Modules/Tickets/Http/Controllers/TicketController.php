<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Controllers;

use Nabilet\Modules\Tickets\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['order_id', 'session_id', 'user_id', 'status']);
        $perPage = (int) $request->get('per_page', 20);

        // Only relations that actually exist on the Ticket model. `session`,
        // `seat` and `inventoryItem` were invented here and threw
        // "Call to undefined relationship" on every list request.
        $query = Ticket::query()->with(['order', 'orderItem', 'scans']);

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
        $ticket->load(['order', 'orderItem', 'scans']);

        return response()->json(['data' => $ticket]);
    }

    public function qrCode(Ticket $ticket): JsonResponse
    {
        // The signed QR string lives in `qr_payload` (NB1.<id>.<token>.<sig>,
        // see QrSigner §30). There is no `qr_code` column in the spec schema —
        // reading it returned null silently or crashed with strict attribute
        // access. And there is no named route `tickets.checkin`: the check-in
        // endpoints are POST /tickets/checkin/scan|verify, so route() there
        // threw UrlGenerationException (500) on every call.
        return response()->json([
            'data' => [
                'ticket_id' => $ticket->id,
                'public_id' => $ticket->public_id,
                'qr_payload' => $ticket->qr_payload,
                'qr_version' => $ticket->qr_version,
                'checkin_endpoint' => url('/api/v1/tickets/checkin/verify'),
            ],
        ]);
    }

    /**
     * Scan history for a ticket (route: GET /tickets/{ticket}/history).
     * The method was referenced by the route but never existed — every call
     * was a 500 (BadMethodCallException). §32: at the door, operators need to
     * see when and where a ticket was first scanned.
     */
    public function history(Ticket $ticket): JsonResponse
    {
        $scans = $ticket->scans()
            ->orderBy('scanned_at', 'desc')
            ->get();

        return response()->json([
            'data' => $scans,
            'meta' => [
                'ticket_id' => $ticket->id,
                'status' => $ticket->status,
                'used_at' => $ticket->used_at?->toIso8601String(),
                'revoked_at' => $ticket->revoked_at?->toIso8601String(),
            ],
        ]);
    }
}
