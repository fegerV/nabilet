<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Database\IdentifierLookup;
use Nabilet\Core\Support\StaffRole;
use Nabilet\Core\Support\Paging;
use Nabilet\Modules\Checkin\Domain\CheckinDevice as CheckinDeviceStatus;
use Nabilet\Modules\Checkin\Services\DeviceTokenIssuer;
use Nabilet\Modules\Checkin\Services\OfflineBundleService;
use Nabilet\Modules\Checkin\Support\SessionLocator;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Tickets\Models\CheckinDevice;
use Nabilet\Modules\Tickets\Models\OfflineBundle;

/**
 * Выдача offline-бандлов (открытие из аудита 09.10 №13: домен был, транспорта
 * не было; `openapi.yaml`: `GET/POST /api/v1/checkin-devices/{device}/bundles`,
 * `GET /api/v1/offline-bundles/{bundle}`).
 *
 * ПОЧЕМУ МАРШРУТ `offline-bundles/{bundle}` БЕЗ `auth:api`
 *   Контракт объявляет И bearer-сотрудника, И device-токен (OR). Middleware
 *   `auth:api` дал бы 401 валидному `nbck_-токену` до контроллера, поэтому
 *   выбор идентификации делается в `show()`: префикс `nbck_` однозначно
 *   отличает токен устройства (`DeviceTokenIssuer`) от токена сотрудника.
 */
final class OfflineBundleController extends Controller
{
    public function __construct(
        private readonly OfflineBundleService $bundles,
        private readonly SessionLocator $sessions,
    ) {}

    public function index(Request $request, string $device): JsonResponse
    {
        // Разрешение идентификатора и отказ при «не нашли» — у одного владельца
        // (`IdentifierLookup::firstOrFail`). Здесь стояла своя копия политики
        // 404, побайтово равная такой же копии в `CheckinDeviceController`.
        $model = IdentifierLookup::firstOrFail(CheckinDevice::class, $device, 'Device');
        $perPage = Paging::limit((int) $request->query('per_page', 50), 50, 100);

        $bundles = $model->offlineBundles()
            ->orderByDesc('generated_at')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($bundles->items())
                ->map(fn (OfflineBundle $bundle) => $this->bundles->present($bundle))
                ->all(),
            'meta' => [
                'current_page' => $bundles->currentPage(),
                'per_page' => $bundles->perPage(),
                'total' => $bundles->total(),
                'last_page' => $bundles->lastPage(),
            ],
        ]);
    }

    public function issue(Request $request, string $device): JsonResponse
    {
        $validated = $request->validate([
            'event_id' => ['required', 'string', 'max:64'],
            'session_id' => ['nullable', 'string', 'max:64'],
        ]);

        $model = IdentifierLookup::firstOrFail(CheckinDevice::class, $device, 'Device');
        $event = Event::query()->where('public_id', (string) $validated['event_id'])->first();

        if ($event === null) {
            throw new NotFoundError('Event', (string) $validated['event_id']);
        }

        $session = isset($validated['session_id']) && $validated['session_id'] !== ''
            ? Session::query()->findOrFail($this->sessions->resolve((string) $validated['session_id']))
            : null;

        $bundle = $this->bundles->issue($model, $event, $session);

        return response()->json(['data' => $this->bundles->present($bundle)], 201);
    }

    public function show(Request $request, string $bundle): JsonResponse
    {
        $model = OfflineBundle::query()->where('public_id', $bundle)->first();

        if ($model === null) {
            throw new NotFoundError('OfflineBundle', $bundle);
        }

        $device = $this->deviceIdOrStaff($request);

        if ($device === null) {
            // Сотрудник: без изменения состояния бандла.
            return response()->json(['data' => $this->bundles->present($model)]);
        }

        // Устройство читает только СВОЙ бандл: чужой — 404
        // (неотличим от несуществующего, анти-IDOR).
        if ((int) $model->checkin_device_id !== $device->id) {
            throw new NotFoundError('OfflineBundle', $bundle);
        }

        // Факт скачивания фиксируется единожды: повтор оффлайн-синхронизации
        // не должен перезаписать первый момент, когда контент покинул сервер.
        if ($model->downloaded_at === null) {
            $model->forceFill(['downloaded_at' => now(), 'status' => 'downloaded'])->save();
        }

        return response()->json(['data' => $this->bundles->present($model->fresh())]);
    }

    /**
     * Токен устройства (префикс `nbck_`) → CheckinDevice; bearer сотрудника →
     * null (после проверки роли). Ни тот ни другой — AuthError 401.
     */
    private function deviceIdOrStaff(Request $request): ?CheckinDevice
    {
        $token = (string) $request->bearerToken();

        if (str_starts_with($token, DeviceTokenIssuer::TOKEN_PREFIX)) {
            $device = CheckinDevice::query()
                ->where('device_token_hash', DeviceTokenIssuer::hash($token))
                ->first();

            if ($device === null || $device->status !== CheckinDeviceStatus::STATUS_ACTIVE) {
                throw AuthError::unauthenticated('Device token is no longer valid.');
            }

            $this->touch($device);

            return $device;
        }

        $user = $request->user('api');

        if ($user === null || ! StaffRole::isStaff($user)) {
            throw AuthError::unauthenticated('Bearer or checker token required.');
        }

        return null;
    }

    private function touch(CheckinDevice $device): void
    {
        try {
            $device->forceFill(['last_seen_at' => now()])->save();
        } catch (\Throwable) {
            // last_seen — телеметрия: её отказ не должен ронять выдачу бандла.
        }
    }
}
