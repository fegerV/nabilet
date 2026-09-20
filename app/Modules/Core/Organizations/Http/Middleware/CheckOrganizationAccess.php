<?php

declare(strict_types=1);

namespace App\Modules\Core\Organizations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Modules\Core\Organizations\Models\Organization;

class CheckOrganizationAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $param = 'publicId'): Response
    {
        $publicId = $request->route($param);

        if (!$publicId) {
            return $next($request);
        }

        $organization = Organization::where('public_id', $publicId)->first();

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        // Проверка: пользователь является участником организации
        $user = $request->user();
        
        if (!$user) {
            abort(401, 'Unauthorized');
        }

        // Проверка наличия пользователя в членах организации
        $isMember = $organization->members()->where('user_id', $user->id)->exists();

        if (!$isMember) {
            abort(403, 'Access denied to this organization');
        }

        // Добавляем организацию в запрос для дальнейшего использования
        $request->merge(['organization' => $organization]);

        return $next($request);
    }
}
