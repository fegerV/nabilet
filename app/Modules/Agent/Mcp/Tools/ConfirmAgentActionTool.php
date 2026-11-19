<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Nabilet\Modules\Agent\Models\AgentAction;
use Nabilet\Modules\Agent\Services\AgentActionService;
use Nabilet\Modules\Agent\Support\AgentCapabilities;

/**
 * Исполнить действие, которое уже одобрил человек.
 *
 * Аннотация IsIdempotent честна: повторный вызов с тем же public_id возвращает
 * сохранённый результат и не трогает бизнес-данные. Аннотации IsReadOnly здесь
 * НЕТ — Tool descriptor должен честно говорить клиенту, что это запись.
 */
#[IsIdempotent]
#[Name('confirm_agent_action')]
#[Description('Исполнить ранее подготовленное и одобренное человеком действие. Возвращает сохранённый результат при повторе.')]
final class ConfirmAgentActionTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'action_public_id' => $schema->string()->description('public_id действия из ответа write-инструмента.'),
        ];
    }

    // Return type deliberately omitted: Response::structured() returns ResponseFactory.
    public function handle(Request $request)
    {
        $v = $request->validate([
            'action_public_id' => ['required', 'string', 'max:26'],
        ]);

        $user = $request->user();
        if (! AgentCapabilities::has($user, AgentCapabilities::ACTIONS_CONFIRM)) {
            return Response::error(
                'Недостаточно полномочий: для исполнения нужна способность ' . AgentCapabilities::ACTIONS_CONFIRM . '.'
            );
        }

        $action = AgentAction::query()->where('public_id', $v['action_public_id'])->first();
        if ($action === null) {
            return Response::error('Действие не найдено.');
        }

        try {
            $result = app(AgentActionService::class)->execute($action, $user);
        } catch (\Nabilet\Core\Errors\ValidationError $e) {
            return Response::error($e->getMessage());
        } catch (\Throwable $e) {
            return Response::error('Ошибка исполнения: ' . mb_substr($e->getMessage(), 0, 500));
        }

        return Response::structured($result + [
            'status' => AgentAction::STATUS_EXECUTED,
            'action_public_id' => $action->public_id,
        ]);
    }
}
