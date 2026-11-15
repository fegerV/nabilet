<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Nabilet\Modules\Agent\Support\AgentScope;
use Nabilet\Modules\Events\Domain\EventStatus;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Nabilet\Modules\Events\Models\Event;

/**
 * Поиск событий организации владельца токена.
 *
 * Возвращает МАКСИМУМ 100 строк, по умолчанию 20, и только поля, нужные
 * для выбора: public_id (ключ для get_event), title, status, даты. Полного
 * описания в списке нет — оно тянется get_event'ом по одному событию;
 * пачка длинных текстов в ответе была бы вычтена из контекста модели
 * без всякой пользы для выбора.
 *
 * `public_id`, а не числовой `id`: проекты API и инструменты агента работают
 * по публичным идентификаторам (см. конвенцию `/me` и embed-маршрутов) —
 * перечисление внутренних ключей модели агенту не нужно и создаёт ложную
 * надежду на обращение по ним.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Name('search_events')]
#[Description('Ищет события организации по названию (частичное совпадение), статусу или без фильтров. Возвращает public_id каждого события — он нужен для get_event.')]
class SearchEventsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Подстрока названия события. Пусто — вернуть все.')
                ->default(''),
            'status' => $schema->string()
                ->enum(EventStatus::all())
                ->description('Фильтр по статусу события. Пусто — вернуть любые.')
                ->default(''),
            'limit' => $schema->integer()
                ->description('Сколько событий вернуть (1–100).')
                ->default(20),
        ];
    }

    public function handle(Request $request)
    {
        $validated = $request->validate(
            [
                'query' => ['nullable', 'string', 'max:200'],
                'status' => ['nullable', 'string', 'in:'.implode(',', EventStatus::all())],
                'limit' => ['integer', 'min:1', 'max:100'],
            ],
            [
                'status.in' => 'Неизвестный статус события. Доступны: '.implode(', ', EventStatus::all()).'.',
                'limit.max' => 'Лимит ограничен 100 — уточните query, чтобы сузить выборку.',
            ],
        );

        $scope = AgentScope::resolve($request->user());

        if ($scope === null) {
            return Response::error('Токен не привязан ни к одной организации — данных для показа нет.');
        }

        $limit = (int) ($validated['limit'] ?? 20);
        $query = trim((string) ($validated['query'] ?? ''));
        $status = (string) ($validated['status'] ?? '');

        $events = $scope->run(function (array $orgIds) use ($limit, $query, $status) {
            $builder = Event::query()
                ->whereIn('organization_id', $orgIds)
                ->orderByDesc('updated_at')
                ->limit($limit)
                ->select(['public_id', 'title', 'slug', 'status', 'updated_at']);

            if ($query !== '') {
                $builder->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%');
            }

            if ($status !== '') {
                $builder->where('status', $status);
            }

            return $builder->get()->map(fn (Event $event): array => [
                'public_id' => (string) $event->public_id,
                'title' => (string) $event->title,
                'slug' => (string) $event->slug,
                'status' => (string) $event->status,
                'updated_at' => (string) $event->updated_at?->toISOString(),
            ])->all();
        });

        if ($events === []) {
            return Response::text('Событий по запросу не найдено (в организациях владельца токена).');
        }

        return Response::structured(['count' => count($events), 'events' => $events]);
    }
}
