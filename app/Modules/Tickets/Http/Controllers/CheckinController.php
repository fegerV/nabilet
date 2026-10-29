<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Http\Controllers;

use Nabilet\Modules\Tickets\Models\Ticket;
use Nabilet\Modules\Tickets\Services\TicketScanService;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\UserOrganization;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Tickets\Models\CheckinDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CheckinController extends Controller
{
    public function __construct(
        private readonly TicketScanService $scanService,
        private readonly OrganizationContext $context,
    ) {}

    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // A11.2: вход строго по подписанному QR. ticket_id опционален и нужен
            // только для кросс-проверки; без qr_payload скан отклоняется (422), что
            // закрывает подделку по перебираемому целочисленному id.
            'qr_payload' => ['required', 'string'],
            'ticket_id' => ['bail', 'nullable', 'integer', 'exists:tickets,id'],
            'device_id' => ['bail', 'nullable', 'integer', 'exists:checkin_devices,id'],
            'session_id' => ['bail', 'required', 'integer', 'exists:sessions,id'],
        ]);

        $organizationId = $this->organizationId($request);

        $result = $this->scanService->scan(
            (int) ($validated['ticket_id'] ?? 0),
            (int) $validated['session_id'],
            $this->deviceId($validated['device_id'] ?? null, $organizationId),
            $validated['qr_payload'] ?? null,
            organizationId: $organizationId,
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
            // A11.2: верификация тоже строго по подписанному QR.
            'qr_payload' => ['required', 'string'],
            'ticket_id' => ['bail', 'nullable', 'integer', 'exists:tickets,id'],
            'session_id' => ['bail', 'required', 'integer', 'exists:sessions,id'],
        ]);

        $verified = $this->scanService->verifyQr($validated['qr_payload']);

        if ($verified === null) {
            return response()->json([
                'data' => [
                    'ticket_id' => (int) ($validated['ticket_id'] ?? 0),
                    'is_valid' => false,
                    'status' => 'invalid_qr',
                    'reason' => 'QR_SIGNATURE_INVALID',
                ],
            ]);
        }

        $ticket = Ticket::where('public_id', $verified['ticketId'])->firstOrFail();

        $isValid = $this->scanService->canCheckin(
            $ticket,
            (int) $validated['session_id'],
            organizationId: $this->organizationId($request),
        );

        return response()->json([
            'data' => [
                'ticket_id' => $ticket->id,
                'is_valid' => (bool) $isValid['valid'],
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

    /**
     * Организация сотрудника, от имени которого идёт чекин.
     *
     * Без организации запрос не расширяется до всех арендаторов: это 422, а не
     * молчаливый пропуск проверки.
     */
    private function organizationId(Request $request): int
    {
        $organizationId = $this->context->idOrNull() ?? UserOrganization::id($request->user());

        if ($organizationId === null) {
            throw new ValidationError([
                'organization_id' => ['organization_id is required for this account.'],
            ]);
        }

        return $organizationId;
    }

    /**
     * Устройство из тела запроса обязано принадлежать той же организации:
     * иначе скан можно записать на чужой сканер.
     */
    private function deviceId(mixed $deviceId, int $organizationId): ?int
    {
        if ($deviceId === null || $deviceId === '') {
            return null;
        }

        $id = (int) $deviceId;

        $exists = CheckinDevice::query()
            ->whereKey($id)
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $exists) {
            throw new NotFoundError('Device', (string) $deviceId);
        }

        return $id;
    }
}
