<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools\Concerns;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Nabilet\Modules\Agent\Services\AgentActionService;
use Nabilet\Modules\Agent\Support\AgentCapabilities;

/**
 * Общий вход write-инструментов: подготовить действие, НЕ исполняя его.
 *
 * Возвращаемый public_idActions нужен затем для confirm_agent_action. Человек
 * видит то же действие в админке и одобряет его; агент не может исполнить без
 * одобрения, а одобренный payload больше не меняется.
 */
trait PreparesAgentAction
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @return Response|ResponseFactory  Response::structured() — фабрика, не Response
     */
    protected function stage(Request $request, string $action, string $capability, array $payload, string $summary)
    {
        $user = $request->user();

        if (! AgentCapabilities::has($user, $capability)) {
            return Response::error(
                'Недостаточно полномочий: токену нужна способность ' . $capability
                . '. Попросите администратора выпустить токен с этой способностью в разделе «ИИ-агент».'
            );
        }

        try {
            $pending = app(AgentActionService::class)->prepare(
                $user,
                $action,
                $capability,
                $payload,
                $summary,
                isset($payload['organization_id']) ? (int) $payload['organization_id'] : null,
            );
        } catch (\Nabilet\Core\Errors\ValidationError $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'status' => 'pending_approval',
            'action_public_id' => $pending->public_id,
            'action' => $pending->action,
            'summary' => $pending->summary,
            'expires_at' => $pending->expires_at?->toIso8601String(),
            'next_step' => 'Сообщите пользователю резюме. Администратор одобрит действие в админке '
                . '(раздел «ИИ-агент» → «Ожидают подтверждения»). Затем вызовите confirm_agent_action '
                . 'с action_public_id. Для возврата администратор при одобрении вводит REFUND.',
        ]);
    }
}
