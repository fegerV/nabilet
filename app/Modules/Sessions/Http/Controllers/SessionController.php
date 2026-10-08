<?php

declare(strict_types=1);

namespace Nabilet\Modules\Sessions\Http\Controllers;

use Nabilet\Core\Errors\ValidationError;
use Nabilet\Modules\Sessions\Models\Session;
use Nabilet\Modules\Inventory\Services\InventoryService;
use Nabilet\Modules\Venues\Models\Hall;
use Nabilet\Modules\Venues\Models\HallSchemaVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SessionController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['event_id', 'hall_id', 'status']);
        $perPage = (int) $request->get('per_page', 20);
        
        $query = Session::query()->with(['event', 'hall', 'venue', 'schemaVersion']);
        
        if (isset($filters['event_id'])) {
            $query->where('event_id', $filters['event_id']);
        }
        
        if (isset($filters['hall_id'])) {
            $query->where('hall_id', $filters['hall_id']);
        }
        
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        
        $sessions = $query->paginate($perPage);
        
        return response()->json([
            'data' => $sessions,
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'per_page' => $sessions->perPage(),
                'total' => $sessions->total(),
                'last_page' => $sessions->lastPage(),
            ],
        ]);
    }

    public function show(Session $session): JsonResponse
    {
        // `venue` в списке связей, а не только `hall`: администратор выбирает
        // площадку, и в списке сеансов должно быть видно, ЧЬИ это залы. Иначе
        // «Большой зал» у двух площадок неотличим.
        $session->load(['event', 'hall', 'venue', 'schemaVersion', 'inventoryItems']);
        
        return response()->json(['data' => $session]);
    }

    public function store(Request $request): JsonResponse
    {
        // `bail` перед каждым `exists:` — не стилистика. Колонки BIGINT, а MySQL
        // не отвергает сравнение со строкой, а приводит её: `WHERE id = '1abc'`
        // совпадает с id = 1 (warning 1292). Без `bail` правило `exists` успевает
        // отработать после `integer` и пропускает мусор (fail-open).
        // Замеры — в `CartController::addItem()`.
        $data = $request->validate([
            'event_id' => ['bail', 'required', 'integer', 'exists:events,id'],
            'venue_id' => ['bail', 'nullable', 'integer', 'exists:venues,id'],
            'hall_id' => ['bail', 'required', 'integer', 'exists:halls,id'],
            'schema_version_id' => ['bail', 'nullable', 'integer', 'exists:hall_schema_versions,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'sales_start_at' => ['nullable', 'date'],
            'sales_end_at' => ['nullable', 'date', 'after:sales_start_at'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:scheduled,on_sale,held,completed,cancelled'],
        ]);

        $data['status'] ??= 'scheduled';
        $data['timezone'] ??= config('app.timezone', 'UTC');

        // БД требует venue_id NOT NULL; форма его не шлёт — берём площадку зала.
        if (empty($data['venue_id']) && !empty($data['hall_id'])) {
            $data['venue_id'] = Hall::query()
                ->where('id', $data['hall_id'])
                ->value('venue_id');
        }

        // БД требует schema_version_id NOT NULL — берём последнюю published схему зала.
        if (empty($data['schema_version_id']) && !empty($data['hall_id'])) {
            $data['schema_version_id'] = HallSchemaVersion::query()
                ->where('hall_id', $data['hall_id'])
                ->where('status', 'published')
                ->orderByDesc('id')
                ->value('id');
        }

        // Обе проверки — ПОСЛЕ вывода значений: площадка выводится из зала, и
        // только после этого пару можно сверить.
        $this->assertHallBelongsToVenue($data);
        $this->assertHallHasPublishedSchema($data);

        $session = Session::create($data);

                                                // Создаём места (геометрия + инвентарь) из схемы зала.
                                                // Канвасный формат редактора конвертируется в rows автоматически
                                                // (HallSchemaVersion::toInventoryFormat).
                                                if ($session->schema_version_id !== null) {
                                                    $schemaVersion = \Nabilet\Modules\Venues\Models\HallSchemaVersion::findOrFail($session->schema_version_id);
                                                    $this->inventoryService->generateFromSchema($schemaVersion, $session);
                                                }

                                $session->load(['event', 'hall', 'schemaVersion']);

        return response()->json(['success' => true, 'data' => $session], 201);
    }

    public function update(Request $request, Session $session): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['bail', 'sometimes', 'integer', 'exists:events,id'],
            'venue_id' => ['bail', 'nullable', 'integer', 'exists:venues,id'],
            'hall_id' => ['bail', 'sometimes', 'integer', 'exists:halls,id'],
            'schema_version_id' => ['bail', 'nullable', 'integer', 'exists:hall_schema_versions,id'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'sales_start_at' => ['nullable', 'date'],
            'sales_end_at' => ['nullable', 'date', 'after:sales_start_at'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'string', 'in:scheduled,on_sale,held,completed,cancelled'],
        ]);

        // БД требует venue_id NOT NULL; при смене зала обновляем площадку зала.
        if (empty($data['venue_id']) && !empty($data['hall_id'])) {
            $data['venue_id'] = Hall::query()
                ->where('id', $data['hall_id'])
                ->value('venue_id');
        }

        // БД требует schema_version_id NOT NULL — берём последнюю published схему зала.
        if (empty($data['schema_version_id']) && !empty($data['hall_id'])) {
            $data['schema_version_id'] = HallSchemaVersion::query()
                ->where('hall_id', $data['hall_id'])
                ->where('status', 'published')
                ->orderByDesc('id')
                ->value('id');
        }

        // Сверка пары нужна и здесь: PATCH может прислать ТОЛЬКО `venue_id` (зал
        // остаётся прежним) или ТОЛЬКО `hall_id` (площадка выводится из зала),
        // поэтому берём ИТОГОВЫЕ значения, а не тело запроса.
        $this->assertHallBelongsToVenue([
            'venue_id' => $data['venue_id'] ?? $session->venue_id,
            'hall_id' => $data['hall_id'] ?? $session->hall_id,
        ]);
        $this->assertHallHasPublishedSchema($data);

        $session->update($data);
        $session->load(['event', 'hall', 'schemaVersion']);

        return response()->json(['success' => true, 'data' => $session]);
    }

    /**
     * Зал обязан принадлежать выбранной площадке.
     *
     * `venue_id` и `hall_id` валидируются по отдельности (`exists:venues,id` и
     * `exists:halls,id`), поэтому `{"venue_id": 1, "hall_id": 2}`, где зал 2
     * стоит на площадке 3, проверку проходил: обе ссылки по отдельности
     * существуют, а пары не существует. Сеанс сохранялся бы, и витрина
     * показывала бы концерт по адресу одной площадки в зале другой — это заметил
     * бы покупатель, а не администратор.
     *
     * Вызывается ПОСЛЕ вывода площадки из зала: если площадку не прислали, она
     * берётся из зала, и тогда пара согласована по построению.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertHallBelongsToVenue(array $data): void
    {
        $hallId = $data['hall_id'] ?? null;
        $venueId = $data['venue_id'] ?? null;

        if (empty($hallId) || empty($venueId)) {
            return;
        }

        $hallVenueId = Hall::query()->whereKey($hallId)->value('venue_id');

        if ($hallVenueId !== null && (int) $hallVenueId !== (int) $venueId) {
            throw new ValidationError(
                ['hall_id' => ['Выбранный зал принадлежит другой площадке. Выберите зал этой площадки.']],
                'Зал не относится к выбранной площадке.'
            );
        }
    }

    /**
     * У зала должна быть опубликованная схема.
     *
     * `sessions.schema_version_id` — NOT NULL, а схема нужна, чтобы создать
     * места: `InventoryService::generateFromSchema()` работает именно по версии
     * схемы. Если у зала нет опубликованной версии, вывод оставлял поле пустым,
     * INSERT падал на NOT NULL, и `POST /sessions` отвечал 500 «Something went
     * wrong» на полностью корректный запрос. Администратор видел внутреннюю
     * ошибку вместо «опубликуйте схему зала».
     *
     * @param  array<string, mixed>  $data
     */
    private function assertHallHasPublishedSchema(array $data): void
    {
        if (empty($data['hall_id']) || ! empty($data['schema_version_id'])) {
            return;
        }

        throw new ValidationError(
            ['hall_id' => ['У зала нет опубликованной схемы — места создавать не из чего. Опубликуйте схему зала и повторите.']],
            'Зал без опубликованной схемы.'
        );
    }

    /** Удаление сеанса. Отменяем, если есть продажи — но в MVP удаляем каскадно. */
    public function destroy(Session $session): JsonResponse
    {
        $session->delete();

        return response()->json(['success' => true, 'message' => 'Session deleted successfully']);
    }
}
