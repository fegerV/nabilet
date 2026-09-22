<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Controllers;

use Nabilet\Modules\Tickets\Models\Ticket;
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
        $validated = $request->validate([
            // `bail`/`integer` guard the BIGINT cast: `tickets.id`, `sessions.id` and
            // `checkin_devices.id` are BIGINT, so `exists` reached with a non-numeric
            // value raises SQLSTATE[22P02] and answers 500 instead of 422. See
            // `CartController::addItem()` for the full explanation.
            'ticket_id' => ['bail', 'required', 'integer', 'exists:tickets,id'],
            'device_id' => ['bail', 'nullable', 'integer', 'exists:checkin_devices,id'],
            'session_id' => ['bail', 'required', 'integer', 'exists:sessions,id'],
        ]);

        $result = $this->scanService->scan(
            (int) $validated['ticket_id'],
            (int) $validated['session_id'],
            isset($validated['device_id']) ? (int) $validated['device_id'] : null
        );

        return response()->json(['data' => $result]);
    }

    /**
     * Check whether a ticket may be admitted to a session, without consuming it.
     *
     * The ticket arrives in the body as `ticket_id`, matching `scan()`. It used to be
     * declared as a `Ticket $ticket` controller parameter, but `POST
     * /tickets/checkin/verify` carries no `{ticket}` route segment for Laravel to bind,
     * so the container tried to construct an empty `Ticket` and the endpoint returned
     * 500 on every call — verified live before this change.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `bail`/`integer` guard the BIGINT cast — see `scan()`.
            'ticket_id' => ['bail', 'required', 'integer', 'exists:tickets,id'],
            'session_id' => ['bail', 'required', 'integer', 'exists:sessions,id'],
        ]);

        $ticket = Ticket::findOrFail($validated['ticket_id']);

        $isValid = $this->scanService->canCheckin($ticket, (int) $validated['session_id']);

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
