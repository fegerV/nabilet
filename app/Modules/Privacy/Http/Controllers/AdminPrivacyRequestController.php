<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Support\Paging;
use Nabilet\Modules\Privacy\Domain\PrivacyRequest;
use Nabilet\Modules\Privacy\Models\PrivacyRequest as PrivacyRequestModel;
use Nabilet\Modules\Privacy\Services\PrivacyRequestService;

/**
 * Обработка обращений субъектов оператором (админка, 152-ФЗ).
 *
 * Отличия от витринного `PrivacyRequestController`:
 *   1. за `auth:api` + роль `admin` стоит middleware (см. routes) — маршруты
 *      здесь не перечисляют auth заново;
 *   2. список — только здесь: у субъекта списка нет (его собственные
 *      обращения он отслеживает по public_id, полученному при подаче);
 *   3. `payload_json` ВИДЕН: контактный адрес и текст обращения — рабочие
 *      данные оператора, без них по обращению не ответить.
 *
 * АДРЕСАЦИЯ — по public_id, не по числовому id: инкрементальный идентификатор
 * наружу не отдаётся нигде в проекте, и админ-роуты не исключение.
 *
 * ПЕРЕХОДЫ — не здесь: матрица жизненного цикла живёт в
 * `PrivacyRequestPolicy`, запись — в `PrivacyRequestService` (отказ — 409
 * `PRIVACY_REQUEST_TRANSITION_DENIED` с контекстом from/to). Повторный PATCH
 * того же статуса — 200 без записи (`no_change`): поток пустых UPDATE не
 * должен выглядеть как работа оператора.
 */
final class AdminPrivacyRequestController extends Controller
{
    public function __construct(private readonly PrivacyRequestService $requests)
    {
    }

    /** GET /api/v1/admin/privacy-requests — очередь обращений, новейшие сверху. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(PrivacyRequest::statuses())],
            'type' => ['nullable', Rule::in(PrivacyRequest::types())],
        ]);

        $perPage = Paging::limit((int) $request->get('per_page', 30), 30, 100);

        // Новые сверху: очередь оператора живёт от «что пришло последним»;
        // разбирать в порядке подачи — вниз списка. Вторичный ключ сортировки
        // не нужен: created_at — DATETIME(6), подач с точностью до микросекунды
        // в один миг не бывает.
        $query = PrivacyRequestModel::query()->orderByDesc('created_at');

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (PrivacyRequestModel $m) => $this->present($m))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /** GET /api/v1/admin/privacy-requests/{publicId} — одно обращение с payload. */
    public function show(string $publicId): JsonResponse
    {
        return response()->json(['data' => $this->present($this->findOr404($publicId), withPayload: true)]);
    }

    /** PATCH /api/v1/admin/privacy-requests/{publicId} — перевод по жизненному циклу. */
    public function update(Request $request, string $publicId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(PrivacyRequest::statuses())],
        ]);

        $model = $this->requests->transition($this->findOr404($publicId), (string) $validated['status']);

        return response()->json(['data' => $this->present($model, withPayload: true)]);
    }

    private function findOr404(string $publicId): PrivacyRequestModel
    {
        $model = $this->requests->findByPublicId($publicId);

        if ($model === null) {
            throw new NotFoundError('PrivacyRequest', $publicId);
        }

        return $model;
    }

    /**
     * Версия для админа добавляет `payload_json` — витринная сериализация его
     * сознательно не отдаёт (см. `PrivacyRequestController::present`).
     *
     * @return array<string, mixed>
     */
    private function present(PrivacyRequestModel $model, bool $withPayload = false): array
    {
        $out = [
            'public_id' => $model->public_id,
            'type' => $model->type,
            'status' => $model->status,
            'created_at' => $model->created_at->toIso8601String(),
            'due_at' => $model->due_at->toIso8601String(),
            'completed_at' => $model->completed_at?->toIso8601String(),
            'user_id' => $model->user_id,
            'is_overdue' => $this->isOverdue($model),
        ];

        if ($withPayload) {
            $out['payload_json'] = $model->payload_json;
        }

        return $out;
    }

    /**
     * Просрочено ли обращение. Сравнение — по ЗАПИСАННОМУ дедлайну (`due_at`),
     * а не по пересчёту от текущего SLA: строка обещала ответ к этому времени,
     * и смена конфига задним числом её обещание не отменяет. Закрытые не
     * бывают просроченными.
     */
    private function isOverdue(PrivacyRequestModel $model): bool
    {
        if (in_array($model->status, [PrivacyRequest::COMPLETED, PrivacyRequest::REJECTED], true)) {
            return false;
        }

        return now()->toImmutable()->gt($model->due_at->toImmutable());
    }
}
