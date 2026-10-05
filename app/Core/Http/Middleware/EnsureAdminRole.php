<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nabilet\Core\Support\StaffRole;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ к админ-API: только пользователи с ролью admin или manager.
 * Применяется к write-роутам (CRUD), публичные read-роуты витрины — открыты.
 *
 * Проверка ролей вынесена в `StaffRole`: тот же вопрос задают контроллеры
 * заказов/платежей/билетов, решая «отдавать все записи или только свои».
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                ],
            ], 401);
        }

        if (!StaffRole::isStaff($user)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to perform this action.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
