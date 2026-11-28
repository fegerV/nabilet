<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nabilet\Modules\Core\Users\Support\StaffRole;
use Symfony\Component\HttpFoundation\Response;

/**
 * Гейт MCP-маршрута: токен должен быть staff-токеном с ability `agent:read`.
 *
 * `auth:sanctum` перед ним пропускает ЛЮБОЙ валидный PAT — включая токен
 * обычного покупателя и токен без скоупов. Эта миддлварь — граница полномочий
 * агента: без неё клиент с чужим (например, сессионным для витрины) токеном
 * прошёл бы аутентификацию и добрался бы до tools/list.
 *
 * Две проверки, обе отказывают ДО вызова сервера:
 *
 *   - `StaffRole::isStaff` — агент наследует роль владельца токена: агент
 *     организатора видит данные организатора. Владелец без staff-роли
 *     (`customer`, «просто пользователь») агентом быть не может.
 *
 *   - `tokenCan('agent:read')` — ability выдаётся при выпуске токена
 *     (теперь и из админки: `/admin/agent/tokens`, и командой
 *     `nabilet:agent-token`). Write-способности (`agent:events:write`,
 *     `agent:schemas:publish`, `agent:refunds:write`, `agent:actions:confirm`)
 *     проверяются ВНУТРИ write-инструментов: чтение — отдельное полномочие,
 *     а не «отсутствие запрета».
 *
 * 403, а не 401: аутентификация выше уже прошла; повторная аутентификация
 * бессмысленна — клиенту нужно перевыпустить токен с нужной ability.
 */
class EnsureAgentAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                ],
            ], 401);
        }

        if (! StaffRole::isStaff($user)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Agent tokens may only be issued to staff members.',
                ],
            ], 403);
        }

        // Разные группы инструментов требуют разных полномочий: бизнес-чтение,
        // системная диагностика, чтение контента или одна из write-способностей.
        // Гейт маршрута проверяет наличие ЛЮБОЙ из них; конкретный инструмент
        // обязан потребовать свою (иначе system:read дал бы доступ к заказам).
        if (! \Nabilet\Modules\Agent\Support\AgentCapabilities::hasAny(
            $user,
            \Nabilet\Modules\Agent\Support\AgentCapabilities::all(),
        )) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Токен не несёт ни одного agent-полномочия. Перевыпустите его в разделе «ИИ-агент».',
                ],
            ], 403);
        }

        return $next($request);
    }
}
