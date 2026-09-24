<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ к админ-API: только пользователи с ролью admin или manager.
 * Применяется к write-роутам (CRUD), публичные read-роуты витрины — открыты.
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

        $roles = $user->roles->pluck('slug');

        if (!$roles->contains('admin') && !$roles->contains('manager')) {
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
