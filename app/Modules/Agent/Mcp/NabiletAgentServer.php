<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Nabilet\Modules\Agent\Mcp\Tools\GetEventTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetOrderTool;
use Nabilet\Modules\Agent\Mcp\Tools\SalesSummaryTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchEventsTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchOrdersTool;

/**
 * MCP-сервер NABILET для внешнего ИИ-агента.
 *
 * ГРАНИЦЫ (на старте — только чтение):
 *
 *   - Каждый инструмент объявлен `#[IsReadOnly]` + `#[IsIdempotent]`: клиенты
 *     (Claude, OpenCode) по аннотации считают вызовы безопасными и могут
 *     выдавать их без подтверждения человека. Добавлять сюда write-инструмент
 *     (отмена заказа, возврат) без отдельного решения нельзя: аннотации
 *     зазвучат враньём, а доверие агента — единственный раз зарабатываемый
 *     ресурс.
 *
 *   - Полномочия на маршруте (`routes/ai.php`): `auth:sanctum` +
 *     `EnsureAgentAbility` — только staff-токен с ability `agent:read`.
 *
 *   - Скоуп данных: организации владельца токена (`AgentScope`); чужие
 *     данные неотличимы от несуществующих.
 *
 * Пять инструментов — сознательно мало: модели выбирают инструменты по имени
 * и описанию, пять ясных лучше двадцати размытых.
 */
#[Name('nabilet-agent')]
#[Version('1.0.0')]
#[Instructions(
    'Read-only MCP-сервер билетной системы NABILET. '
    .'Данные ограничены организациями владельца токена; все инструменты только читают. '
    .'Начинайте с search_events, чтобы найти событие (вернёт public_id), '
    .'затем get_event — детали и сеансы; search_orders/get_order — заказы; '
    .'sales_summary — сводные продажи за период. Идентификаторы — public_id (26 символов), не числовые id.'
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
    ];
}
