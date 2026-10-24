<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Database\IdentifierLookup;
use Nabilet\Modules\Checkin\Domain\CheckinDevice as DevicePolicy;
use Nabilet\Modules\Checkin\Services\DeviceTokenIssuer;
use Nabilet\Modules\Tickets\Models\CheckinDevice;

/**
 * Управление устройствами контроля входа (`openapi.yaml`:
 * `GET/POST /api/v1/admin/checkin/devices`, `PATCH .../devices/{device}`).
 *
 * ЗАЧЕМ АДМИНСКАЯ ЧАСТЬ ВООБЩЕ ПОЯВИЛАСЬ
 *   Без неё guard `checkerAuth` нечем вооружить: `device_token_hash` не писал
 *   никто, токен выдавать было некому и негде. Три эндпоинта чекина без этого
 *   контроллера — дверь без ключа.
 *
 * ТОКЕН ВОЗВРАЩАЕТСЯ РОВНО ОДИН РАЗ, В ОТВЕТЕ НА `POST`
 *   В базе только sha256 (`DeviceTokenIssuer::hash`). Ни list, ни update его не
 *   отдают — иначе «показать токен ещё раз» стало бы чтением секрета из
 *   обычного админского запроса.
 *
 * ОТЗЫВ, А НЕ УДАЛЕНИЕ
 *   `status = revoked`. Удаление устройства оторвало бы `ticket_scans.device_id`
 *   от записей, и аудит перестал бы отвечать на вопрос «кем принят билет».
 *   Поэтому в `PATCH` нет и варианта удалить строку.
 */
final class CheckinDeviceController extends Controller
{
    public function __construct(
        private readonly DeviceTokenIssuer $issuer
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

        $devices = CheckinDevice::query()
            ->when($request->query('organization_id'), fn ($q, $id) => $q->where('organization_id', $id))
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $devices->items(),
            'meta' => [
                'current_page' => $devices->currentPage(),
                'per_page' => $devices->perPage(),
                'total' => $devices->total(),
                'last_page' => $devices->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'bail', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ]);

        $issued = $this->issuer->issue(
            organizationId: (int) $validated['organization_id'],
            name: (string) $validated['name'],
            platform: (string) ($validated['platform'] ?? 'android'),
            appVersion: $validated['app_version'] ?? null,
        );

        return response()->json([
            'data' => [
                'device' => $issued['device'],
                // Единственный момент, когда токен существует вне процесса
                // выдачи. Дальше — только хеш.
                'token' => $issued['token'],
            ],
        ], 201);
    }

    public function update(Request $request, string $device): JsonResponse
    {
        $model = $this->find($device);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            // Активным устройство делает только повторная выдача токена: `PATCH`
            // не умеет вернуть жизнь отозванному сканеру, и это правильно —
            // иначе утечка токена лечилась бы флажком в админке.
            'status' => ['sometimes', 'string', 'in:' . DevicePolicy::STATUS_ACTIVE . ',' . DevicePolicy::STATUS_REVOKED],
        ]);

        if (isset($validated['status']) && $validated['status'] === DevicePolicy::STATUS_REVOKED) {
            $this->issuer->revoke($model);
            unset($validated['status']);
        }

        if ($validated !== []) {
            $model->update($validated);
        }

        return response()->json(['data' => $model->fresh()]);
    }

    /**
     * Идентификатор в пути — число или `public_id`, как и везде в админке
     * (`orders`, `events`). Неявная привязка модели здесь не используется:
     * она приводит путь к int, и `/admin/checkin/devices/1abc` открывал бы
     * устройство 1.
     */
    private function find(string $device): CheckinDevice
    {
        $model = IdentifierLookup::first(CheckinDevice::class, $device);

        if ($model === null) {
            throw new NotFoundError('Device', $device);
        }

        return $model;
    }
}
