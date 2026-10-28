<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Database\IdentifierLookup;
use Nabilet\Core\Support\Paging;
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
        $perPage = Paging::limit((int) $request->query('per_page', 50), 50, 100);

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
        // Разрешение идентификатора и отказ при «не нашли» — у одного владельца
        // (`IdentifierLookup::firstOrFail`). Здесь стояла своя копия политики
        // 404, побайтово равная такой же копии в `OfflineBundleController`.
        $model = IdentifierLookup::firstOrFail(CheckinDevice::class, $device, 'Device');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            // ТОЛЬКО `revoked`. Отзыв необратим, и это не удобство, а смысл
            // отзыва: `DeviceTokenIssuer::revoke()` меняет ТОЛЬКО статус —
            // `device_token_hash` остаётся прежним, потому что `ticket_scans`
            // ссылается на устройство и строку нельзя удалить. Значит статус —
            // единственное, что отделяет утёкший токен от работы.
            //
            // Здесь стояло `in:active,revoked`, и ветка ниже снимала `status`
            // из набора только для `revoked` — `active` доезжал до
            // `$model->update()`. То есть `PATCH {"status":"active"}` оживлял
            // отозванный сканер вместе с утёкшим токеном, хотя комментарий на
            // две строки выше обещал обратное. Нашлось тестом
            // `test_patch_cannot_revive_a_revoked_device`.
            //
            // Вернуть устройство к жизни может только новая выдача токена
            // (`POST /admin/checkin/devices`): у неё новое значение
            // `device_token_hash`, то есть старый токен не воскресает вместе со
            // статусом. `disabled` из CHECK сюда не выставляется намеренно: в
            // проверке `AuthenticateChecker` он ведёт себя ровно как `revoked`,
            // а вернуть его обратно было бы нечем — второе имя для того же
            // состояния без выхода из него.
            'status' => ['sometimes', 'string', 'in:' . DevicePolicy::STATUS_REVOKED],
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
}
