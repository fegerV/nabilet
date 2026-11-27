<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Nabilet\Modules\Agent\Services\AgentDiagnostics;

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
            default => ['error' => 'Неизвестный диагностический раздел.'],
        };

        return Response::structured([
            'section' => $this->section(),
            'data' => $report,
        ]);
    }
}
