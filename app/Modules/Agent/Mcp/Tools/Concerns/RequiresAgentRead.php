<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Mcp\Tools\Concerns;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Nabilet\Modules\Agent\Support\AgentCapabilities;

/**
 * Бизнес-чтение своей организации — отдельное полномочие agent:read.
 * Маршрут /mcp допускает любой agent-ability, поэтому каждый инструмент
 * обязан потребовать СВОЮ способность внутри handle().
 */
trait RequiresAgentRead
{
    protected function guardAgentRead(Request $request): ?Response
    {
        if (AgentCapabilities::has($request->user(), AgentCapabilities::READ)) {
            return null;
        }

        return Response::error(
            'Для чтения бизнес-данных организации нужна способность agent:read. '
            . 'Перевыпустите токен в разделе «ИИ-агент».'
        );
    }
}
