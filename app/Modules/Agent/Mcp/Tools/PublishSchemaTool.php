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
 * Подготовка публикации версии схемы зала.
 *
 * Публикация необратимо замораживает геометрию (триггер/политика), поэтому
 * действие сначала сохраняется в agent_actions и уходит человеку. Дополнительно
 * поддерживается base_revision — optimistic lock против параллельной правки.
 */
#[Name('publish_schema')]
#[Description('Подготовить публикацию версии схемы зала. Замораживает геометрию — требуется одобрение человека.')]
final class PublishSchemaTool extends Tool
{
    use PreparesAgentAction;

    public function schema(JsonSchema $schema): array
    {
        return [
            'venue_public_id' => $schema->string()->description('Публичный ID площадки (26 символов).'),
            'schema_version_id' => $schema->integer()->description('ID версии схемы, которую нужно опубликовать.'),
            'base_revision' => $schema->integer()->description('Ревизия, которую видел агент; защищает от параллельной правки.'),
            'organization_id' => $schema->integer()->description('Организация, если у токена их несколько.'),
        ];
    }

    // Return type deliberately omitted: Response::structured() returns ResponseFactory.
    public function handle(Request $request)
    {
        $v = $request->validate([
            'venue_public_id' => ['required', 'string', 'max:26'],
            'schema_version_id' => ['required', 'integer', 'min:1'],
            'base_revision' => ['nullable', 'integer', 'min:1'],
            'organization_id' => ['nullable', 'integer'],
        ]);

        return $this->stage(
            $request,
            'publish_schema',
            AgentCapabilities::SCHEMAS_PUBLISH,
            $v,
            sprintf('Опубликовать версию схемы #%d площадки %s', $v['schema_version_id'], $v['venue_public_id']),
        );
    }
}
