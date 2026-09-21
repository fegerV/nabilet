<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Controllers;

use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Models\TicketScan;
use Nabilet\Modules\Tickets\Services\TicketScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CheckinController extends Controller
{
    public function __construct(
        private readonly TicketScanService $scanService
    ) {}

    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'ticket_id' => ['required', 'exists:tickets,id'],
            'device_id' => ['nullable', 'exists:checkin_devices,id'],
            'session_id' => ['required', 'exists:sessions,id'],
        ]);

        $result = $this->scanService->scan(
            (int) $request->get('ticket_id'),
            (int) $request->get('session_id'),
            $request->get('device_id') ? (int) $request->get('device_id') : null
        );

        return response()->json(['data' => $result]);
    }

    public function verify(Ticket $ticket, Request $request): JsonResponse
    {
        $sessionId = $request->get('session_id');
        
        if (!$sessionId) {
            return response()->json(['error' => 'session_id required'], 422);
        }

        $isValid = $this->scanService->canCheckin($ticket, (int) $sessionId);

        return response()->json([
            'data' => [
                'ticket_id' => $ticket->id,
                'is_valid' => $isValid,
                'status' => $ticket->status,
            ],
        ]);
    }

    public function history(Ticket $ticket): JsonResponse
    {
        $scans = $ticket->scans()->with('device')->orderBy('scanned_at', 'desc')->get();

        return response()->json([
            'data' => $scans,
        ]);
    }
}
