<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Database\IdentifierLookup;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\Paging;
use Nabilet\Core\Support\UserOrganization;
use Nabilet\Core\Tenancy\OrganizationContext;
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
        private readonly DeviceTokenIssuer $issuer,
        private readonly OrganizationContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = Paging::limit((int) $request->query('per_page', 50), 50, 100);
        $organizationId = $this->organizationId($request);

        // Чужой organization_id в query не расширяет выборку: он отвергается,
        // чтобы клиент не решил, что получил запрошенный срез.
        $requested = $request->query('organization_id');

        if ($requested !== null
            && (! is_scalar($requested) || (int) $requested !== $organizationId)
        ) {
            throw new ValidationError([
                'organization_id' => ['organization_id does not belong to this account.'],
            ]);
        }

        $devices = CheckinDevice::query()
            ->where('organization_id', $organizationId)
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

        // Выдать устройство можно только своей организации: чужой
        // organization_id в теле — 404, как несуществующая организация.
        $this->context->assertCanAccess(
            (int) $validated['organization_id'],
            'Organization',
            (string) $validated['organization_id'],
        );

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

        // Устройство другой организации неотличимо от несуществующего.
        $this->context->assertCanAccess($model->organization_id, 'Device', $device);

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

    /**
     * Организация администратора. Без неё список не расширяется до всех
     * арендаторов — это 422, а не молчаливая выдача чужих устройств.
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
