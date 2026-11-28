<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Nabilet\Modules\Agent\Mcp\Tools\Concerns\RequiresAgentRead;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Nabilet\Modules\Agent\Support\AgentScope;
use Nabilet\Modules\Events\Models\Event;
use Nabilet\Modules\Sessions\Models\Session;

/**
 * Детали события: описание, сеансы и остатки мест.
 *
 * Чужое событие отвечает ошибкой «не найдено», а не «доступ запрещён»:
 * то же правило, что и на API (`assertCanAccess` → 404) — подтверждение
 * существования чужой записи само по себе утечка.
 *
 * Инвентаризация: capacity/available живут в `inventory_items` по сеансу,
 * а не в `sessions`. Сеанс без инвентаря (зал ещё не создан) отдаёт
 * inventory: null — «не настроено», а не нули, которые агент прочитал бы
 * как «всё продано».
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Name('get_event')]
#[Description('Детали события по public_id: описание, статусы сеансов, вместимость и остатки мест. События чужих организаций неотличимы от несуществующих.')]
class GetEventTool extends Tool
{
    use RequiresAgentRead;

    public function schema(JsonSchema $schema): array
    {
        return [
            'public_id' => $schema->string()
                ->description('Публичный идентификатор события (26 символов, из search_events).')
                ->required(),
        ];
    }

    public function handle(Request $request)
    {
        if ($denied = $this->guardAgentRead($request)) {
            return $denied;
        }
        $validated = $request->validate(
            ['public_id' => ['required', 'string', 'max:26']],
            ['public_id.required' => 'Укажите public_id события — получить его можно инструментом search_events.'],
        );

        $scope = AgentScope::resolve($request->user());

        if ($scope === null) {
            return Response::error('Токен не привязан ни к одной организации — данных для показа нет.');
        }

        $publicId = (string) $validated['public_id'];

        /** @var Event|null $event */
        $event = $scope->run(function (array $orgIds) use ($publicId) {
            return Event::query()
                ->whereIn('organization_id', $orgIds)
                ->where('public_id', $publicId)
                ->first();
        });

        if ($event === null) {
            return Response::error(sprintf('Событие %s не найдено среди организаций владельца токена.', $publicId));
        }

        $sessions = $scope->run(function (array $orgIds) use ($event) {
            // Инвентарь одним запросом: capacity и available суммируются по сеансу.
            $capacityBySession = DB::table('inventory_items')
                ->whereIn('inventory_items.organization_id', $orgIds)
                ->whereIn('session_id', $event->sessions()->select('id'))
                ->groupBy('session_id')
                ->selectRaw('session_id, SUM(COALESCE(capacity, 0)) as capacity, SUM(COALESCE(available_quantity, 0)) as available')
                ->get()
                ->keyBy('session_id');

            return $event->sessions()
                ->orderBy('starts_at')
                ->get()
                ->map(function ($session) use ($capacityBySession): array {
                    $inv = $capacityBySession->get($session->id);

                    return [
                        'public_id' => (string) $session->public_id,
                        'starts_at' => (string) $session->starts_at,
                        'timezone' => (string) $session->timezone,
                        'status' => (string) $session->status,
                        'inventory' => $inv === null
                            ? null
                            : [
                                'capacity' => (int) $inv->capacity,
                                'available' => (int) $inv->available,
                                'sold' => max(0, (int) $inv->capacity - (int) $inv->available),
                            ],
                    ];
                })
                ->all();
        });

        return Response::structured([
            'public_id' => (string) $event->public_id,
            'title' => (string) $event->title,
            'slug' => (string) $event->slug,
            'status' => (string) $event->status,
            'short_description' => (string) $event->short_description,
            'description' => mb_substr((string) $event->description, 0, 2000),
            'age_limit' => $event->age_limit !== null ? (int) $event->age_limit : null,
            'updated_at' => (string) $event->updated_at?->toISOString(),
            'sessions' => $sessions,
        ]);
    }
}
