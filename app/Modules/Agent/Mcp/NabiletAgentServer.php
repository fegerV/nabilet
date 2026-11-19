<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Nabilet\Modules\Agent\Mcp\Tools\ConfirmAgentActionTool;
use Nabilet\Modules\Agent\Mcp\Tools\CreateEventTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetEventTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetOrderTool;
use Nabilet\Modules\Agent\Mcp\Tools\PublishSchemaTool;
use Nabilet\Modules\Agent\Mcp\Tools\RefundTool;
use Nabilet\Modules\Agent\Mcp\Tools\SalesSummaryTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchEventsTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchOrdersTool;

/**
 * MCP-сервер NABILET для внешнего ИИ-агента.
 *
 * ГРАНИЦЫ (чтение + write с подтверждением человека):
 *
 *   - Read-инструменты объявлены `#[IsReadOnly]` + `#[IsIdempotent]` — клиенты
 *     (Claude, OpenCode) считают их безопасными и не просят подтверждения.
 *
 *   - Write-инструменты (create_event, publish_schema, refund) НЕ read-only:
 *     они лишь готовят действие и сохраняют неизменяемый снимок в agent_actions.
 *     Реально меняют данные только `confirm_agent_action`, и только после того,
 *     как человек одобрил снимок в админке. Для возврата одобрение требует
 *     отдельной фразы REFUND. Аннотации честны: доверие агента — ресурс, который
 *     зарабатывается один раз.
 *
 *   - Полномочия на маршруте (`routes/ai.php`): `auth:sanctum` +
 *     `EnsureAgentAbility` — только staff-токен с ability `agent:read`. Write-
 *     способности (`agent:events:write` и др.) проверятся ВНУТРИ инструментов.
 *
 *   - Скоуп данных: организации владельца токена (`AgentScope`); чужие данные
 *     неотличимы от несуществующих — и при чтении, и при подготовке действия.
 */
#[Name('nabilet-agent')]
#[Version('1.0.0')]
#[Instructions(
    'MCP-сервер билетной системы NABILET. Данные ограничены организациями владельца токена. '
    .'ЧТЕНИЕ: начните с search_events (вернёт public_id), затем get_event — детали и сеансы; '
    .'search_orders/get_order — заказы; sales_summary — сводные продажи. '
    .'ЗАПИСЬ: create_event, publish_schema и refund только ПОДГОТАВЛИВАЮТ действие и возвращают '
    .'action_public_id. Обязательно скажите пользователю резюме действия: администратор одобряет его '
    .'в админке (раздел «ИИ-агент» → «Ожидают подтверждения»); для возврата он вводит REFUND. '
    .'Только после одобрения вызовите confirm_agent_action с action_public_id. '
    .'Никогда не обещайте, что изменение уже применено, пока confirm не вернул результат. '
    .'Идентификаторы — public_id (26 символов), не числовые id.'
)]
class NabiletAgentServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        SalesSummaryTool::class,
        SearchEventsTool::class,
        GetEventTool::class,
        SearchOrdersTool::class,
        GetOrderTool::class,
        CreateEventTool::class,
        PublishSchemaTool::class,
        RefundTool::class,
        ConfirmAgentActionTool::class,
    ];
}
