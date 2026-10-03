<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\NotFoundError;
use Nabilet\Core\Errors\TenantContextMissingError;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Tests\Support\TestCase;

/**
 * Multi-tenancy is the highest-consequence correctness property of this product:
 * one installation may serve several organizers (ТЗ §10), so a scoping bug means
 * one client sees another client's orders and customers.
 *
 * The dangerous failure mode is the *permissive* default — "if no organization is
 * set, return everything". These tests pin the opposite behaviour: fail closed.
 */
final class OrganizationContextTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        $ctx = new OrganizationContext();

        $this->assertFalse($ctx->isSet());
        $this->assertNull($ctx->tryId());
    }

    public function testIdThrowsWhenNoContextIsActive(): void
    {
        $ctx = new OrganizationContext();

        $this->assertThrows(TenantContextMissingError::class, static fn () => $ctx->id());
    }

    public function testIdReturnsTheActiveOrganization(): void
    {
        $ctx = new OrganizationContext();
        $ctx->set('org-1');

        $this->assertTrue($ctx->isSet());
        $this->assertSame('org-1', $ctx->id());
        $this->assertSame('org-1', $ctx->tryId());
    }

    public function testClearRemovesContextAndRestoresFailClosedBehaviour(): void
    {
        $ctx = new OrganizationContext();
        $ctx->set('org-1');
        $ctx->clear();

        $this->assertFalse($ctx->isSet());
        $this->assertThrows(TenantContextMissingError::class, static fn () => $ctx->id());
    }

    public function testSwitchingOrganizationsReplacesContext(): void
    {
        $ctx = new OrganizationContext();
        $ctx->set('org-1');
        $ctx->set('org-2');

        $this->assertSame('org-2', $ctx->id());
    }

    public function testWithoutScopeAllowsSystemLevelWork(): void
    {
        $ctx = new OrganizationContext();

        $result = $ctx->withoutScope(static fn (): string => 'ran');

        $this->assertSame('ran', $result);
        $this->assertTrue($ctx->isBypassing() === false, 'bypass must be closed after the callback');
        $this->assertThrows(TenantContextMissingError::class, static fn () => $ctx->id());
    }

    /**
     * A bypass left open by an exception would silently disable tenant isolation
     * for the rest of the request — the worst possible outcome. The finally block
     * must always restore the previous depth.
     */
    public function testBypassIsClosedEvenWhenTheCallbackThrows(): void
    {
        $ctx = new OrganizationContext();

        try {
            $ctx->withoutScope(static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($ctx->isBypassing());
        $this->assertThrows(TenantContextMissingError::class, static fn () => $ctx->id());
    }

    public function testNestedBypassRestoresPreviousDepth(): void
    {
        $ctx = new OrganizationContext();

        $ctx->withoutScope(function () use ($ctx): void {
            $this->assertTrue($ctx->isBypassing());

            $ctx->withoutScope(function () use ($ctx): void {
                $this->assertTrue($ctx->isBypassing());
            });

            $this->assertTrue($ctx->isBypassing(), 'outer bypass must still be active');
        });

        $this->assertFalse($ctx->isBypassing());
    }

    public function testIdIsEmptyInsideBypassRatherThanThrowing(): void
    {
        $ctx = new OrganizationContext();

        $ctx->withoutScope(function () use ($ctx): void {
            $this->assertSame('', $ctx->id());
        });
    }

    /**
     * assertOwns() is the guard at the edge of every write: it stops a caller from
     * smuggling a foreign organization_id into a request payload.
     */
    public function testAssertOwnsAcceptsMatchingOrganization(): void
    {
        $ctx = new OrganizationContext();
        $ctx->set('org-1');

        $ctx->assertOwns('org-1');
        $this->assertTrue(true);
    }

    public function testAssertOwnsRejectsForeignOrganizationAsNotFound(): void
    {
        $ctx = new OrganizationContext();
        $ctx->set('org-1');

        // 404, not 403 — a 403 would confirm the row exists elsewhere
        $this->assertThrows(NotFoundError::class, static fn () => $ctx->assertOwns('org-2', 'order'));
    }

    public function testAssertOwnsIsSkippedInsideBypass(): void
    {
        $ctx = new OrganizationContext();

        $ctx->withoutScope(function () use ($ctx): void {
            $ctx->assertOwns('org-anything');
        });

        $this->assertTrue(true);
    }

    public function testListenersAreNotifiedOnChange(): void
    {
        $ctx = new OrganizationContext();
        $seen = [];

        $ctx->onChange(function (?string $id) use (&$seen): void {
            $seen[] = $id;
        });

        $ctx->set('org-1');
        $ctx->set('org-1');   // no change -> no notification
        $ctx->set('org-2');
        $ctx->clear();

        $this->assertSame(['org-1', 'org-2', null], $seen);
    }
}
