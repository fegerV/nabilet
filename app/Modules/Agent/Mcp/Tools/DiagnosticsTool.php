<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Nabilet\Modules\Agent\Services\AgentDiagnostics;
use Nabilet\Modules\Agent\Support\AgentCapabilities;

/**
 * Общая основа для read-only диагностических инструментов агента.
 *
 * Всё общее — схема аргумента `limit`, проверка границ и обезличенный отчёт —
 * живёт здесь. Конкретный инструмент объявляет только section(), имя и
 * аннотации; это исключает расхождение между инструментами одной группы.
 */
abstract class DiagnosticsTool extends Tool
{
    abstract protected function section(): string;

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Сколько записей вернуть (1..50).')
                ->default(10),
        ];
    }

    public function handle(Request $request)
    {
        $validated = $request->validate(
            ['limit' => ['sometimes', 'integer', 'min:1', 'max:50']],
        );
        $limit = (int) ($validated['limit'] ?? 10);
        $diagnostics = app(AgentDiagnostics::class);
        $section = $this->section();

        // Версия и список возможностей — безопасные общие сведения. Остальные
        // срезы включают системные журналы, очередь, бэкапы, пути и глобальные
        // настройки: только agent:system:read + системный администратор.
        if (! in_array($section, ['applicationVersion', 'apiCapabilities'], true)) {
            if (! AgentCapabilities::has($request->user(), AgentCapabilities::SYSTEM_READ)) {
                return Response::error(
                    'Для системной диагностики нужна способность agent:system:read. '
                    . 'Перевыпустите токен в разделе «ИИ-агент».'
                );
            }
            if (! \Nabilet\Modules\Core\Users\Support\StaffRole::isPlatformAdmin($request->user())) {
                return Response::error(
                    'Системная диагностика доступна только системному администратору '
                    . '(NABILET_PLATFORM_ADMINS).'
                );
            }
        }

        // Явный match вместо динамического вызова: PHPStan видит реальные
        // сигнатуры и не даёт «обезличенному» tools-слою стать прыжком в любую
        // функцию сервиса.
        $report = match ($this->section()) {
            'applicationVersion' => $diagnostics->applicationVersion(),
            'systemStatus' => $diagnostics->systemStatus(),
            'systemRequirements' => $diagnostics->systemRequirements(),
            'cronStatus' => $diagnostics->cronStatus(),
            'queueStatus' => $diagnostics->queueStatus(),
            'recentErrors' => $diagnostics->recentErrors($limit),
            'failedJobs' => $diagnostics->failedJobs($limit),
            'databaseStatus' => $diagnostics->databaseStatus(),
            'moduleStatus' => $diagnostics->moduleStatus(),
            'integrationStatus' => $diagnostics->integrationStatus(),
            'backupStatus' => $diagnostics->backupStatus(),
            'updateStatus' => $diagnostics->updateStatus(),
            'apiCapabilities' => $diagnostics->apiCapabilities(),
            'installationReport' => $diagnostics->installationReport(),
            'readonlySelfcheck' => $diagnostics->readonlySelfcheck(),
            'routeStatus' => $diagnostics->routeStatus(),
            default => ['error_message' => 'Неизвестный диагностический раздел.'],
        };

        return Response::structured([
            'section' => $this->section(),
            'data' => $report,
        ]);
    }
}
