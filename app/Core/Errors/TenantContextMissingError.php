<?php

declare(strict_types=1);

namespace Nabilet\Core\Errors;

/**
 * Raised when tenant-scoped data is touched without an active organization.
 *
 * This is the fail-closed guarantee. The alternative — returning all rows when no
 * organization is set — is precisely how multi-tenant SaaS products leak one
 * customer's data to another. We would rather hard-fail a request in development
 * than silently serve cross-tenant data in production.
 *
 * System-level work (installer, scheduled jobs, admin console) must opt in
 * explicitly via OrganizationContext::withoutScope(), which makes the bypass
 * visible in code review instead of implicit.
 *
 * @see docs/ARCHITECTURE.md — "Multi-tenancy"
 */
class TenantContextMissingError extends AppError
{
    public function __construct(string $model = '')
    {
        parent::__construct(
            $model === ''
                ? 'No organization context is active. Tenant-scoped queries are refused.'
                : sprintf('No organization context is active while querying %s.', $model),
            'TENANT_CONTEXT_MISSING',
            500,
            ['model' => $model],
            false // not operational: this is a programming error, not user input
        );
    }
}
