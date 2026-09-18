<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nabilet\Core\Tenancy\OrganizationContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active organization BEFORE anything tenant-scoped runs.
 *
 * Order matters: this middleware must sit before authorization and before any
 * controller. If a policy or a repository query executes first, it does so without a
 * tenant context — and in a fail-closed system that is an exception, while in a
 * permissive system it is a cross-tenant data leak.
 *
 * Resolution strategy:
 *   1. An authenticated user's organization wins.
 *   2. A `Host`-based subdomain mapping (white-label deployments).
 *   3. Paths in `nabilet.tenancy.system_paths` (installer, health, webhooks,
 *      sitemap) run with an explicit bypass instead of a guess.
 *
 * The bypass is granted through `withoutScope()`, so it is visible in code review
 * rather than implicit.
 */
final class ResolveOrganizationContext
{
    public function __construct(private readonly OrganizationContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isSystemPath($request)) {
            return $this->context->withoutScope(fn (): Response => $next($request));
        }

        $organizationId = $this->resolveFromUser($request) ?? $this->resolveFromHost($request);

        // Deliberately no "default organization" fallback: guessing here would mean
        // an unauthenticated request lands in whichever tenant happens to be first.
        if ($organizationId !== null) {
            $this->context->set($organizationId);
        }

        return $next($request);
    }

    private function resolveFromUser(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $organizationId = $user->organization_id ?? null;

        return $organizationId === null ? null : (string) $organizationId;
    }

    /**
     * White-label / partner subdomains, e.g. tickets.partner.ru.
     * Resolution goes through a hook so a plugin can supply its own mapping without
     * a core change.
     */
    private function resolveFromHost(Request $request): ?string
    {
        if (! function_exists('apply_filters')) {
            return null;
        }

        $organizationId = apply_filters('tenancy.resolve_from_host', null, $request->getHost());

        return is_string($organizationId) && $organizationId !== '' ? $organizationId : null;
    }

    private function isSystemPath(Request $request): bool
    {
        $paths = (array) config('nabilet.tenancy.system_paths', []);

        foreach ($paths as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
