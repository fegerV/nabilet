<?php

declare(strict_types=1);

namespace Nabilet\Core\Idempotency;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Tenancy\OrganizationContext;

/**
 * Builds the `scope` half of the (scope, key_hash) uniqueness pair.
 *
 * THE TENANT MUST BE PART OF THE SCOPE.
 *
 * `uq_idempotency_scope_hash` is unique on (scope, key_hash) alone — there is no
 * organization_id on the table. If the scope were just "orders.create", then two
 * organizations whose client happens to generate the same key would collide, and
 * the second one would be served the FIRST one's stored response: its order id,
 * its totals, its seat numbers. That is a cross-tenant data leak through a
 * caching layer, which is about the worst place to have one.
 *
 * So the organization id is always prefixed, and it is obtained fail-closed:
 * OrganizationContext::id() throws when no tenant is resolved rather than
 * returning null, because a silently empty tenant would put us right back in the
 * leak.
 */
final class IdempotencyScope
{
    /** scope is VARCHAR(100); leave room for the 26-char public id and a label. */
    public const MAX_LENGTH = 100;

    public static function make(OrganizationContext $context, string $scope): string
    {
        if ($scope === '') {
            throw new DomainRuleViolation(
                'An idempotency scope must be named (e.g. "orders.create").',
                'INVALID_IDEMPOTENCY_SCOPE'
            );
        }

        // Throws when no tenant is active. Inside withoutScope() it returns ''
        // instead, and an empty tenant is exactly the leak this class prevents —
        // so refuse rather than build a scope that starts with ':'.
        $organizationId = $context->id('idempotency');

        if ($organizationId === '') {
            throw new DomainRuleViolation(
                'Cannot build an idempotency scope outside an organization context: '
                . 'the key would be shared across every tenant.',
                'INVALID_IDEMPOTENCY_SCOPE'
            );
        }

        $scoped = $organizationId . ':' . $scope;

        if (strlen($scoped) > self::MAX_LENGTH) {
            throw new DomainRuleViolation(
                sprintf(
                    'Idempotency scope "%s" exceeds %d characters.',
                    $scoped,
                    self::MAX_LENGTH
                ),
                'INVALID_IDEMPOTENCY_SCOPE'
            );
        }

        return $scoped;
    }
}
