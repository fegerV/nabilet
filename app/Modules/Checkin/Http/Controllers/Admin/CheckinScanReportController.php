<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Controllers\Admin;

use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\Paging;
use Nabilet\Core\Support\UserOrganization;
use Nabilet\Core\Tenancy\OrganizationContext;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Modules\Tickets\Models\TicketScan;

/**
 * `GET /api/v1/admin/checkin/scans` — журнал входов (openapi.yaml
 * `adminCheckinScans`, tag `Admin Checkin`).
 *
 * ЗАЧЕМ ЭТО ОТДЕЛЬНЫЙ ЭНДПОИНТ
 *   `GET /tickets/{ticket}/history` отвечает на вопрос «что с ЭТИМ билетом».
 *   Отчёт на входе — другой вопрос: «что происходит на ЭТОМ сеансе прямо
 *   сейчас» — сколько пущено, сколько отказов, с каких устройств. Без него
 *   супервизор мероприятия смотрел бы в список заказов и считал вручную.
 *
 * ФИЛЬТРЫ — ТОЛЬКО ТЕ, ЧТО ОБЪЯВЛЕНЫ КОНТРАКТОМ: `session_id`, `result`,
 * постранично. «По организации» в фильтрах нет, но организация администратора
 * применяется к выборке ВСЕГДА: без неё журнал отдавал бы сканы всех
 * арендаторов. Это не фильтр клиента, а граница доступа.
 */
final class CheckinScanReportController extends Controller
{
    public function __construct(
        private readonly OrganizationContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = Paging::limit((int) $request->query('per_page', 50), 50, 200);
        $organizationId = $this->organizationId($request);

        $scans = TicketScan::query()
            // Журнал — только по билетам заказов своей организации: у
            // `ticket_scans` нет собственного `organization_id`, а сеанс и
            // устройство сами по себе не доказывают принадлежность билета.
            ->whereHas('ticket.order', function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId);
            })
            // Сеанс: сканы лежат с `session_id` на самой строке, join не нужен.
            ->when($request->query('session_id'), function ($query, $sessionId): void {
                $query->where('session_id', $sessionId);
            })
            // Результат — словарь домена (`ScanOutcome`), а не контракта:
            // в базе хранится то, что произошло. UI переводит, если нужно.
            ->when($request->query('result'), function ($query, $result): void {
                $query->where('result', $result);
            })
            ->when($request->query('device_id'), function ($query, $deviceId): void {
                $query->where('device_id', $deviceId);
            })
            ->with(['device'])
            // Свежие сверху: отчёт открывают во время мероприятия.
            ->orderByDesc('scanned_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $scans->items(),
            'meta' => [
                'current_page' => $scans->currentPage(),
                'per_page' => $scans->perPage(),
                'total' => $scans->total(),
                'last_page' => $scans->lastPage(),
            ],
        ]);
    }

    /**
     * Организация администратора. Без неё журнал не расширяется до всех
     * арендаторов — это 422, а не молчаливая выдача чужих сканов.
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
}
