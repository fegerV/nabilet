<?php

declare(strict_types=1);

namespace Nabilet\Modules\Core\Organizations\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Nabilet\Core\Errors\AuthError;
use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Modules\Core\Organizations\Repositories\OrganizationRepository;

/**
 * Refuses access to an organization the caller is not a member of.
 *
 * The three `abort()` calls this middleware used to contain each produced a body
 * outside the §66 envelope (`{"message":"Unauthorized","exception":"…"}`), so a
 * client could not branch on a stable code. They now throw `AuthError` /
 * `NotFoundError` and are rendered by `ApiExceptionRenderer`.
 *
 * 401 and 403 stay distinct on purpose: 401 tells the client to refresh the token and
 * retry, 403 tells it that retrying is pointless. Collapsing them into one status is
 * what makes a frontend retry-loop forever against a permission it will never have.
 */
class CheckOrganizationAccess
{
    public function __construct(
        protected OrganizationRepository $repository
    ) {}

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

        $organization = $this->repository->findByPublicId($publicId);

        if (!$organization) {
            throw new NotFoundError('Organization', $publicId);
        }

        // Проверка: пользователь является участником организации
        $user = $request->user();

        if (!$user) {
            throw AuthError::unauthenticated();
        }

        // Проверка наличия пользователя в членах организации
        $isMember = $organization->members()->where('user_id', $user->id)->exists();

        if (!$isMember) {
            // 403, not 404: the caller already proved they know this organization exists
            // by naming it, and `NotFoundError` here would be misleading rather than
            // protective.
            throw new AuthError('Access denied to this organization.', 'FORBIDDEN', 403);
        }

        // Добавляем организацию в запрос для дальнейшего использования
        $request->merge(['organization' => $organization]);

        return $next($request);
    }
}
