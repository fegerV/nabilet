<?php

declare(strict_types=1);

namespace Nabilet\Modules\Agent\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nabilet\Core\Support\StaffRole;
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
 *     (`nabilet:agent-token`). Токен пользователя с другой способностью
 *     (например, future `agent:write`) сюда не пройдёт: чтение — отдельное
 *     полномочие, а не его отсутствие.
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

        if (! method_exists($user, 'tokenCan') || ! $user->tokenCan('agent:read')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'This token does not carry the "agent:read" ability.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
