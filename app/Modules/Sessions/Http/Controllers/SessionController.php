<?php

declare(strict_types=1);

namespace Nabilet\Modules\Sessions\Http\Controllers;

use Nabilet\Modules\Sessions\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['event_id', 'hall_id', 'status']);
        $perPage = (int) $request->get('per_page', 20);
        
        $query = Session::query()->with(['event', 'hall', 'schemaVersion']);
        
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
        $session->load(['event', 'hall', 'schemaVersion', 'inventoryItems']);
        
        return response()->json(['data' => $session]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'integer', 'exists:events,id'],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'hall_id' => ['required', 'integer', 'exists:halls,id'],
            'schema_version_id' => ['nullable', 'integer', 'exists:hall_schema_versions,id'],
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
                            $data['venue_id'] = \Nabilet\Modules\Venues\Models\Hall::query()
                                ->where('id', $data['hall_id'])
                                ->value('venue_id');
                        }

                        // БД требует schema_version_id NOT NULL — берём последнюю published схему зала.
                        if (empty($data['schema_version_id']) && !empty($data['hall_id'])) {
                            $data['schema_version_id'] = \Nabilet\Modules\Venues\Models\HallSchemaVersion::query()
                                ->where('hall_id', $data['hall_id'])
                                ->where('status', 'published')
                                ->orderByDesc('id')
                                ->value('id');
                        }

                        $session = Session::create($data);
        $session->load(['event', 'hall', 'schemaVersion']);

        return response()->json(['success' => true, 'data' => $session], 201);
    }

    public function update(Request $request, Session $session): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['sometimes', 'integer', 'exists:events,id'],
            'venue_id' => ['nullable', 'integer', 'exists:venues,id'],
            'hall_id' => ['sometimes', 'integer', 'exists:halls,id'],
            'schema_version_id' => ['nullable', 'integer', 'exists:hall_schema_versions,id'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'sales_start_at' => ['nullable', 'date'],
            'sales_end_at' => ['nullable', 'date', 'after:sales_start_at'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'string', 'in:scheduled,on_sale,held,completed,cancelled'],
        ]);

        // БД требует venue_id NOT NULL; при смене зала обновляем площадку зала.
                if (empty($data['venue_id']) && !empty($data['hall_id'])) {
                    $data['venue_id'] = \Nabilet\Modules\Venues\Models\Hall::query()
                        ->where('id', $data['hall_id'])
                        ->value('venue_id');
                }

                // БД требует schema_version_id NOT NULL — берём последнюю published схему зала.
                if (empty($data['schema_version_id']) && !empty($data['hall_id'])) {
                    $data['schema_version_id'] = \Nabilet\Modules\Venues\Models\HallSchemaVersion::query()
                        ->where('hall_id', $data['hall_id'])
                        ->where('status', 'published')
                        ->orderByDesc('id')
                        ->value('id');
                }

                $session->update($data);
        $session->load(['event', 'hall', 'schemaVersion']);

        return response()->json(['success' => true, 'data' => $session]);
    }

    /** Удаление сеанса. Отменяем, если есть продажи — но в MVP удаляем каскадно. */
    public function destroy(Session $session): JsonResponse
    {
        $session->delete();

        return response()->json(['success' => true, 'message' => 'Session deleted successfully']);
    }
}
