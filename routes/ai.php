<?php

use Laravel\Mcp\Facades\Mcp;
use Nabilet\Modules\Agent\Http\Middleware\EnsureAgentAbility;
use Nabilet\Modules\Agent\Mcp\NabiletAgentServer;

/*
 * MCP-сервер внешнего ИИ-агента (модуль Agent).
 *
 * Полномочия, по слоям:
 *   auth:sanctum        — Bearer-токен Sanctum (PAT, ability `agent:read`);
 *                         сессионные cookie витрины/админки здесь не работают.
 *   EnsureAgentAbility  — токен обязан быть staff-токеном С ability
 *                         `agent:read`; PAT без неё отвечает 403 на любом
 *                         вызове, включая tools/list.
 *   throttle:60,1       — 60 вызовов в минуту на токен: MCP-клиент спокойно
 *                         укладывается, сканирующий перебор — нет.
 *
 * Транспорт — Streamable HTTP (POST /mcp), работает на shared hosting как
 * обычный web-маршрут, без долгоживущих процессов.
 *
 * Данные каждого инструмента ограничены организациями владельца токена
 * (`AgentScope`); инструменты объявлены read-only аннотациями.
 */
Mcp::web('/mcp', NabiletAgentServer::class)
    ->middleware(['auth:sanctum', EnsureAgentAbility::class, 'throttle:60,1']);
