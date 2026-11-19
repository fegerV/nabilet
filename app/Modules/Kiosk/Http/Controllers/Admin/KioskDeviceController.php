<?php

declare(strict_types=1);

namespace Nabilet\Modules\Kiosk\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Database\IdentifierLookup;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Support\Paging;
use Nabilet\Core\Support\UserOrganization;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Modules\Kiosk\Domain\KioskDevice as DevicePolicy;
use Nabilet\Modules\Kiosk\Domain\KioskSale as SalePolicy;
use Nabilet\Modules\Kiosk\Models\KioskDevice;
use Nabilet\Modules\Kiosk\Models\KioskSale;
use Nabilet\Modules\Kiosk\Services\KioskAnalyticsService;
use Nabilet\Modules\Kiosk\Services\KioskTokenIssuer;

/**
 * Админка парка информационных киосков.
 *
 * Три группы операций:
 *   1. реестр (создать, список, карточку, переименовать, адрес);
 *   2. управление (включить/выключить ПРОДАЖИ, временно отключить, отозвать,
 *      ротировать токен);
 *   3. удалённое обновление (директива версии/URL/чексуммы и её отмена).
 *
 * Токен показывается ровно один раз — в ответе создания или ротации.
 * Список и карточка отдают только хеш, который бесполезен без секретной части.
 */
final class KioskDeviceController extends Controller
{
    public function __construct(
        private readonly KioskTokenIssuer $issuer,
        private readonly KioskAnalyticsService $analytics,
        private readonly OrganizationContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $perPage = Paging::limit((int) $request->query('per_page', 50), 50, 100);

        $requested = $request->query('organization_id');

        if ($requested !== null
            && (! is_scalar($requested) || (int) $requested !== $organizationId)
        ) {
            throw new ValidationError([
                'organization_id' => ['organization_id does not belong to this account.'],
            ]);
        }

        $devices = KioskDevice::query()
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
            'summary' => $this->analytics->summary($organizationId),
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->analytics->summary($this->organizationId($request)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'bail', 'integer', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'location_address' => ['nullable', 'string', 'max:500'],
            'location_note' => ['nullable', 'string', 'max:500'],
            'platform' => ['nullable', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'sales_enabled' => ['nullable', 'boolean'],
        ]);

        $this->context->assertCanAccess(
            (int) $validated['organization_id'],
            'Organization',
            (string) $validated['organization_id'],
        );

        // новый киоск по умолчанию НЕ продаёт: включать продажи — осознанное
        // действие администратора после установки устройства.
        $issued = $this->issuer->issue(
            organizationId: (int) $validated['organization_id'],
            name: (string) $validated['name'],
            locationAddress: $validated['location_address'] ?? null,
            locationNote: $validated['location_note'] ?? null,
            platform: (string) ($validated['platform'] ?? 'web_kiosk'),
            appVersion: $validated['app_version'] ?? null,
            salesEnabled: (bool) ($validated['sales_enabled'] ?? false),
        );

        return response()->json([
            'data' => [
                'device' => $issued['device'],
                'token' => $issued['token'],
            ],
        ], 201);
    }

    public function show(Request $request, string $kiosk): JsonResponse
    {
        $device = $this->find($kiosk);
        $organizationId = $this->organizationId($request);

        return response()->json([
            'data' => [
                'device' => $device,
                'stats' => $this->analytics->deviceStats((int) $device->id, $organizationId),
                'recent_sales' => KioskSale::query()
                    ->where('kiosk_device_id', $device->id)
                    ->orderByDesc('id')
                    ->limit(20)
                    ->get(),
            ],
        ]);
    }

    /**
     * Редактирование карточки и управление продажами.
     *
     * ВАЖНО: `sales_enabled` и `status` — РАЗНЫЕ вещи. Первое разрешает/запрещает
     * продажу, оставляя устройство на связи. Второе — полное отключение обмена
     * (временно `disabled` или необратимо `revoked` — отдельным эндпоинтом).
     */
    public function update(Request $request, string $kiosk): JsonResponse
    {
        $device = $this->find($kiosk);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'location_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'location_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'platform' => ['sometimes', 'string', 'max:32'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sales_enabled' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'in:' . DevicePolicy::STATUS_ACTIVE . ',' . DevicePolicy::STATUS_DISABLED],
        ]);

        // Отзыв необратим: PATCH не должен воскресить отозванный киоск вместе
        // с его (потенциально утёкшим) токеном. Проверено тестом.
        if ($device->status === DevicePolicy::STATUS_REVOKED && isset($validated['status'])) {
            throw new ValidationError([
                'status' => ['A revoked kiosk cannot be reactivated; issue a new token instead.'],
            ]);
        }

        if (isset($validated['status']) && $validated['status'] === DevicePolicy::STATUS_DISABLED) {
            // Выключенный киоск не должен продавать, даже если флаг продаж был включён.
            $validated['sales_enabled'] = false;
        }

        if ($device->status === DevicePolicy::STATUS_REVOKED && ($validated['sales_enabled'] ?? false) === true) {
            throw new ValidationError([
                'sales_enabled' => ['A revoked kiosk cannot sell.'],
            ]);
        }

        $device->update($validated);

        return response()->json(['data' => $device->fresh()]);
    }

    /** Ротация токена: старое устройство отзывается, выдаётся новое. */
    public function rotateToken(Request $request, string $kiosk): JsonResponse
    {
        $device = $this->find($kiosk);
        $issued = $this->issuer->rotate($device);

        return response()->json([
            'data' => [
                'device' => $issued['device'],
                'token' => $issued['token'],
            ],
        ], 201);
    }

    /** Отзыв необратим: старый токен не ожить, продажи выключены. */
    public function revoke(Request $request, string $kiosk): JsonResponse
    {
        $device = $this->find($kiosk);

        return response()->json([
            'data' => $this->issuer->revoke($device),
        ]);
    }

    /**
     * Директива удалённого обновления.
     *
     * Сервер НЕ исполняет код и не хранит архив: он публикует версию, URL и
     * checksum. Киоск забирает директиву heartbeat'ом и сам решает, когда
     * обновляться. Это честно и безопасно — сервер не может «залить» исполняемый
     * код на устройство помимо его воли.
     */
    public function issueUpdate(Request $request, string $kiosk): JsonResponse
    {
        $device = $this->find($kiosk);

        $validated = $request->validate([
            'target_version' => ['required', 'string', 'max:50'],
            'update_url' => ['required', 'string', 'url', 'max:512'],
            'update_checksum' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/i'],
        ]);

        if ($device->status === DevicePolicy::STATUS_REVOKED) {
            throw new ValidationError(['kiosk' => ['A revoked kiosk cannot be updated.']]);
        }

        $device->forceFill([
            'target_version' => $validated['target_version'],
            'update_url' => $validated['update_url'],
            'update_checksum' => $validated['update_checksum'] ?? null,
            'update_status' => DevicePolicy::UPDATE_PENDING,
            'update_issued_at' => now(),
            'update_error' => null,
            'update_finished_at' => null,
        ])->save();

        return response()->json(['data' => $device->fresh()]);
    }

    public function cancelUpdate(Request $request, string $kiosk): JsonResponse
    {
        $device = $this->find($kiosk);

        if ($device->update_status !== DevicePolicy::UPDATE_PENDING) {
            throw new ValidationError(['update' => ['There is no pending update to cancel.']]);
        }

        $device->forceFill([
            'update_status' => DevicePolicy::UPDATE_NONE,
            'target_version' => null,
            'update_url' => null,
            'update_checksum' => null,
            'update_finished_at' => now(),
        ])->save();

        return response()->json(['data' => $device->fresh()]);
    }

    /** Журнал продаж конкретного киоска. */
    public function sales(Request $request, string $kiosk): JsonResponse
    {
        $device = $this->find($kiosk);
        $perPage = Paging::limit((int) $request->query('per_page', 50), 50, 200);

        $query = KioskSale::query()
            ->where('kiosk_device_id', $device->id)
            ->orderByDesc('id');

        $status = $request->query('status');

        if ($status !== null && is_string($status) && in_array($status, SalePolicy::STATUSES, true)) {
            $query->where('status', $status);
        }

        $sales = $query->paginate($perPage);

        return response()->json([
            'data' => $sales->items(),
            'meta' => [
                'current_page' => $sales->currentPage(),
                'per_page' => $sales->perPage(),
                'total' => $sales->total(),
                'last_page' => $sales->lastPage(),
            ],
        ]);
    }

    /** id или public_id; чужой киоск неотличим от несуществующего. */
    private function find(string $kiosk): KioskDevice
    {
        $device = IdentifierLookup::firstOrFail(KioskDevice::class, $kiosk, 'Kiosk');
        $this->context->assertCanAccess($device->organization_id, 'Kiosk', $kiosk);

        return $device;
    }

    private function organizationId(Request $request): int
    {
        $organizationId = $this->context->idOrNull() ?? UserOrganization::id($request->user());

        if ($organizationId === null) {
            throw new ValidationError([
                'organization_id' => ['organization_id is required for this account.'],
            ]);
        }

        return (int) $organizationId;
    }
}
