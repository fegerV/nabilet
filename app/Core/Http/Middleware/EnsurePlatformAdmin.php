<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nabilet\Modules\Core\Users\Support\StaffRole;
use Symfony\Component\HttpFoundation\Response;

/**
 * Операции уровня платформы: бэкапы всех арендаторов, системные логи,
 * миграции, глобальные настройки платежей/почты/Диска.
 *
 * Список e-mail задаётся NABILET_PLATFORM_ADMINS. Пока он пуст, поведение
 * совпадает с обычным admin (обратная совместимость с одной организацией) —
 * на multi-client установке список обязан быть заполнен.
 */
final class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => [
                'code' => 'UNAUTHENTICATED',
                'message' => 'Authentication required.',
                'details' => [],
            ]], 401);
        }
        if (! StaffRole::isPlatformAdmin($user)) {
            return response()->json(['error' => [
                'code' => 'PLATFORM_ADMIN_REQUIRED',
                'message' => 'Эта операция доступна только платформенному администратору.',
                'details' => [],
            ]], 403);
        }

        return $next($request);
    }
}
