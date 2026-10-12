<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Controllers\Admin;

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
 * постранично. Больше не добавлено намеренно: «по организации» в фильтрах нет,
 * а молча довешивать организацию заказчика к каждого запроса — значит менять
 *   смысл выдачи без согласования с контрактом.
 */
final class CheckinScanReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));

        $scans = TicketScan::query()
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
}
