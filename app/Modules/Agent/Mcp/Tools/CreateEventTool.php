<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Nabilet\Modules\Agent\Mcp\Tools\Concerns\PreparesAgentAction;
use Nabilet\Modules\Agent\Support\AgentCapabilities;

/**
 * Подготовка черновика мероприятия.
 *
 * НЕ публикует и НЕ открывает продажи: статус всегда draft, а публикация —
 * отдельный переход с проверками (EventPublicationPolicy). Агент не может
 * выставить проверенное решение заочно.
 */
#[Name('create_event')]
#[Description('Подготовить черновик мероприятия (афиша/описание/площадка/дата/схема). Требует одобрения человека; публикацию не выполняет.')]
final class CreateEventTool extends Tool
{
    use PreparesAgentAction;

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Название мероприятия.'),
            'venue_id' => $schema->integer()->description('ID площадки из карточки площадки в админке.'),
            'starts_at' => $schema->string()->description('Дата и время начала в формате YYYY-MM-DD HH:MM:SS.'),
            'schema_version_id' => $schema->integer()->description('Обязательно: ID опубликованной версии схемы выбранной площадки.'),
            'slug' => $schema->string()->description('Необязательный латинский адрес страницы; если пусто — из названия.'),
            'description' => $schema->string()->description('Полное описание для витрины.'),
            'short_description' => $schema->string()->description('Краткое описание.'),
            'ends_at' => $schema->string()->description('Окончание (не ранее начала).'),
            'timezone' => $schema->string()->description('Часовой пояс, например Asia/Yekaterinburg.'),
            'poster' => $schema->string()->description('URL афиши, загруженной через медиатеку админки.'),
            'age_limit' => $schema->string()->description('Возрастное ограничение.'),
            'duration_minutes' => $schema->integer()->description('Длительность в минутах.'),
            'organization_id' => $schema->integer()->description('Организация, если у токена их несколько.'),
        ];
    }

    // Return type deliberately omitted: Response::structured() returns ResponseFactory.
    public function handle(Request $request)
    {
        $v = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'venue_id' => ['required', 'integer'],
            'starts_at' => ['required', 'string'],
            'schema_version_id' => ['required', 'integer'],
            'slug' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string'],
            'ends_at' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string'],
            'poster' => ['nullable', 'string'],
            'age_limit' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'],
        ]);

        return $this->stage(
            $request,
            'create_event',
            AgentCapabilities::EVENTS_WRITE,
            $v,
            sprintf('Создать черновик «%s» на площадке #%d, начало %s', $v['title'], $v['venue_id'], $v['starts_at']),
        );
    }
}
