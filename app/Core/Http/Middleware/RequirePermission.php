<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nabilet\Core\Errors\AuthError;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission gate (ТЗ §9).
 *
 * Usage in routes:
 *
 *     Route::post('/orders/{order}/refund', ...)->middleware('permission:orders.refund');
 *
 * The permission list is checked against the authenticated user's roles. Permissions
 * are resolved through a hook so the Users module owns the storage and the kernel
 * stays unaware of how roles are persisted.
 *
 * IMPORTANT: this middleware answers "is this principal allowed to use this
 * capability at all". It is NOT a substitute for per-resource authorization — a
 * Cashier with `orders.view` must still not see another organization's orders, and
 * that check belongs in a policy close to the data.
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw AuthError::unauthenticated();
        }

        foreach ($permissions as $permission) {
            if (! $this->userHas($user, $permission)) {
                throw AuthError::forbidden($permission);
            }
        }

        return $next($request);
    }

    private function userHas(object $user, string $permission): bool
    {
        if (function_exists('apply_filters')) {
            $granted = apply_filters('auth.user_has_permission', null, $user, $permission);

            if (is_bool($granted)) {
                return $granted;
            }
        }

        // Fallback: the model exposes its own check. Keeps the kernel free of a hard
        // dependency on the Users module.
        if (method_exists($user, 'hasPermission')) {
            return (bool) $user->hasPermission($permission);
        }

        return false; // fail closed
    }
}
