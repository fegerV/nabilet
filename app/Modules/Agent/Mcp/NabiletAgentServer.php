<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Nabilet\Modules\Agent\Mcp\Tools\ConfirmAgentActionTool;
use Nabilet\Modules\Agent\Mcp\Tools\ContentTool;
use Nabilet\Modules\Agent\Mcp\Tools\CreateEventTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetApiCapabilitiesTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetApplicationVersionTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetBackupStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetHallSchemaTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetPageTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetSeoReportTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetSiteSettingsTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetThemeSettingsTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetCronStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetDatabaseStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetEventTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetFailedJobsTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetInstallationReportTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetIntegrationStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetModuleStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetOrderTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetQueueStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetRecentErrorsTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetRouteStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetSystemRequirementsTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetSystemStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\GetUpdateStatusTool;
use Nabilet\Modules\Agent\Mcp\Tools\PublishSchemaTool;
use Nabilet\Modules\Agent\Mcp\Tools\RefundTool;
use Nabilet\Modules\Agent\Mcp\Tools\RunReadonlySelfcheckTool;
use Nabilet\Modules\Agent\Mcp\Tools\SalesSummaryTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchEventsTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchHallsTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchMediaTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchOrdersTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchPagesTool;
use Nabilet\Modules\Agent\Mcp\Tools\SearchVenuesTool;
use Nabilet\Modules\Agent\Mcp\Tools\ValidateEventTool;
use Nabilet\Modules\Agent\Mcp\Tools\ValidateHallSchemaTool;
use Nabilet\Modules\Agent\Mcp\Tools\ValidatePaymentConfigurationTool;
use Nabilet\Modules\Agent\Mcp\Tools\ValidateTicketTemplateTool;

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
    .'ДИАГНОСТИКА (только чтение): run_readonly_selfcheck — быстрая проверка; get_system_status, '
    .'get_system_requirements, get_cron_status, get_queue_status, get_recent_errors, get_failed_jobs, '
    .'get_database_status, get_module_status, get_integration_status, get_backup_status, '
    .'get_update_status, get_application_version, get_api_capabilities, get_installation_report, '
    .'get_route_status. Диагностика не содержит секретов и персональных данных. '
    .'КОНТЕНТ И ПРОВЕРКИ (read-only): search_pages → get_page, search_media, '
    .'get_site_settings, get_theme_settings, get_seo_report, search_venues → search_halls → '
    .'get_hall_schema; validate_hall_schema, validate_event, validate_ticket_template, '
    .'validate_payment_configuration. Все данные ограничены организациями владельца токена. '
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
        // Диагностика (read-only, без секретов и персональных данных).
        GetApplicationVersionTool::class,
        GetSystemStatusTool::class,
        GetSystemRequirementsTool::class,
        GetCronStatusTool::class,
        GetQueueStatusTool::class,
        GetRecentErrorsTool::class,
        GetFailedJobsTool::class,
        GetDatabaseStatusTool::class,
        GetModuleStatusTool::class,
        GetIntegrationStatusTool::class,
        GetBackupStatusTool::class,
        GetUpdateStatusTool::class,
        GetApiCapabilitiesTool::class,
        GetInstallationReportTool::class,
        RunReadonlySelfcheckTool::class,
        GetRouteStatusTool::class,
        // Контент и валидаторы (read-only, organization scope).
        SearchPagesTool::class,
        GetPageTool::class,
        SearchMediaTool::class,
        GetSiteSettingsTool::class,
        GetThemeSettingsTool::class,
        GetSeoReportTool::class,
        SearchVenuesTool::class,
        SearchHallsTool::class,
        GetHallSchemaTool::class,
        ValidateHallSchemaTool::class,
        ValidateEventTool::class,
        ValidateTicketTemplateTool::class,
        ValidatePaymentConfigurationTool::class,
    ];
}
