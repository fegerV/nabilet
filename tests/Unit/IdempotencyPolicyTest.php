<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Errors\TenantContextMissingError;
use Nabilet\Core\Idempotency\IdempotencyDecision;
use Nabilet\Core\Idempotency\IdempotencyPolicy;
use Nabilet\Core\Idempotency\IdempotencyRecord;
use Nabilet\Core\Idempotency\IdempotencyScope;
use Nabilet\Core\Tenancy\OrganizationContext;
use Nabilet\Tests\Support\TestCase;

/**
 * Idempotency is what stands between "the mobile client retried" and "the
 * customer was charged twice".
 *
 * The trivial case — same key, finished response, replay it — is not why this
 * file exists. These tests are for the cases where the comfortable answer is
 * wrong:
 *   - a duplicate arriving WHILE the first request is still running
 *   - a replay after the record has expired
 *   - a 5xx, which must stay retryable
 *   - two tenants colliding on one key
 */
final class IdempotencyPolicyTest extends TestCase
{
    private const NOW = '2026-09-20 12:00:00';

    private function policy(): IdempotencyPolicy
    {
        return new IdempotencyPolicy();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function record(
        string $requestHash,
        ?int $status = 200,
        ?string $expiresAt = '2026-09-21 12:00:00',
        ?string $lockedAt = '2026-09-20 11:59:00',
    ): IdempotencyRecord {
        return new IdempotencyRecord(
            scope: 'org1:orders.create',
            keyHash: 'kh',
            requestHash: $requestHash,
            responseStatus: $status,
            responseBody: '{"ok":true}',
            lockedAt: $lockedAt === null ? null : new \DateTimeImmutable($lockedAt),
            expiresAt: $expiresAt === null ? null : new \DateTimeImmutable($expiresAt),
        );
    }

    // ── the basic decisions ──────────────────────────────────────────────────

    public function testNoKeyMeansSkip(): void
    {
        $this->assertSame(
            IdempotencyDecision::SKIP,
            $this->policy()->decide(null, 's', 'h', null)
        );

        $this->assertSame(
            IdempotencyDecision::SKIP,
            $this->policy()->decide('', 's', 'h', null)
        );
    }

    public function testNothingRecordedMeansProceed(): void
    {
        $this->assertSame(
            IdempotencyDecision::PROCEED,
            $this->policy()->decide('key-1', 's', 'h', null)
        );
    }

    public function testFinishedRecordIsReplayed(): void
    {
        $existing = $this->record('same-hash');

        $this->assertSame(
            IdempotencyDecision::REPLAY,
            $this->policy()->decide('key-1', 's', 'same-hash', $existing, $this->now())
        );
    }

    public function testSameKeyWithDifferentPayloadIsAConflict(): void
    {
        $existing = $this->record('original-hash');

        $this->assertSame(
            IdempotencyDecision::CONFLICT,
            $this->policy()->decide('key-1', 's', 'different-hash', $existing, $this->now())
        );
    }

    // ── the cases that actually cause incidents ──────────────────────────────

    /**
     * A duplicate arriving while the handler is still running is THE scenario the
     * key exists for. Executing again "just in case" is what double-charges.
     */
    public function testInFlightDuplicateIsRefusedNotReExecuted(): void
    {
        $existing = $this->record('h', null, '2026-09-21 12:00:00', '2026-09-20 11:59:59');

        $this->assertTrue($existing->isInFlight());
        $this->assertSame(
            IdempotencyDecision::IN_FLIGHT,
            $this->policy()->decide('key-1', 's', 'h', $existing, $this->now())
        );
    }

    /**
     * An expired record is not evidence of anything — the client is entitled to
     * reuse the key. Treating it as a replay would hand back a stale response for
     * a request that was never actually served.
     */
    public function testExpiredRecordDoesNotBlockReuse(): void
    {
        $existing = $this->record('h', 200, '2026-09-19 12:00:00');

        $this->assertTrue($existing->isExpired($this->now()));
        $this->assertSame(
            IdempotencyDecision::PROCEED,
            $this->policy()->decide('key-1', 's', 'h', $existing, $this->now())
        );
    }

    public function testRecordWithoutExpiryNeverExpires(): void
    {
        $existing = $this->record('h', 200, null);

        $this->assertFalse($existing->isExpired($this->now()));
        $this->assertSame(
            IdempotencyDecision::REPLAY,
            $this->policy()->decide('key-1', 's', 'h', $existing, $this->now())
        );
    }

    /**
     * A 5xx must stay retryable. Caching it would turn a transient outage into a
     * permanent one: every subsequent retry would return the same 502 forever.
     */
    public function testServerErrorsAreNotRecorded(): void
    {
        $this->assertFalse($this->policy()->shouldRecord(500));
        $this->assertFalse($this->policy()->shouldRecord(502));
        $this->assertFalse($this->policy()->shouldRecord(503));
    }

    public function testSuccessfulAndClientResponsesAreRecorded(): void
    {
        $this->assertTrue($this->policy()->shouldRecord(200));
        $this->assertTrue($this->policy()->shouldRecord(201));
        $this->assertTrue($this->policy()->shouldRecord(422));
    }

    public function testOverlongKeyIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->policy()->decide(str_repeat('a', 192), 's', 'h', null)
        );
    }

    // ── tenant isolation ─────────────────────────────────────────────────────

    /**
     * The table is unique on (scope, key_hash) and has NO organization_id. If the
     * scope did not carry the tenant, two organizations whose client generated the
     * same key would collide — and the second would be served the first one's
     * response, with its order id and its seat numbers.
     */
    public function testScopeCarriesTheOrganization(): void
    {
        $context = new OrganizationContext();
        $context->set('org_aaaaaaaaaaaaaaaaaaaaaa');

        $this->assertSame(
            'org_aaaaaaaaaaaaaaaaaaaaaa:orders.create',
            IdempotencyScope::make($context, 'orders.create')
        );
    }

    public function testTwoOrganizationsNeverShareAScope(): void
    {
        $a = new OrganizationContext();
        $a->set('org_a');
        $b = new OrganizationContext();
        $b->set('org_b');

        $this->assertNotSame(
            IdempotencyScope::make($a, 'orders.create'),
            IdempotencyScope::make($b, 'orders.create')
        );
    }

    /** Fail-closed: without a tenant there is no safe scope to build. */
    public function testScopeWithoutOrganizationIsRefused(): void
    {
        $this->assertThrows(
            TenantContextMissingError::class,
            fn () => IdempotencyScope::make(new OrganizationContext(), 'orders.create')
        );
    }

    public function testEmptyScopeIsRefused(): void
    {
        $context = new OrganizationContext();
        $context->set('org_a');

        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => IdempotencyScope::make($context, '')
        );
    }

    // ── hashing ──────────────────────────────────────────────────────────────

    /**
     * The stored column is key_hash CHAR(64). Storing the raw key would put a
     * client-supplied value in a column whose name promises a hash, and would let
     * a very long key blow past the 64 chars.
     */
    public function testKeyIsStoredHashed(): void
    {
        $hash = $this->policy()->keyHash('client-key-1');

        $this->assertSame(64, strlen($hash));
        $this->assertSame(hash('sha256', 'client-key-1'), $hash);
        $this->assertNotSame('client-key-1', $hash);
    }

    public function testRequestFingerprintIsStable(): void
    {
        $this->assertSame(
            $this->policy()->requestHash('{"a":1}'),
            $this->policy()->requestHash('{"a":1}')
        );

        $this->assertNotSame(
            $this->policy()->requestHash('{"a":1}'),
            $this->policy()->requestHash('{"a":2}')
        );
    }

    public function testRecordFromDatabaseRow(): void
    {
        $row = IdempotencyRecord::fromArray([
            'scope' => 'org_a:orders.create',
            'key_hash' => 'kh',
            'request_hash' => 'rh',
            'response_status' => 201,
            'response_body' => '{"id":1}',
            'locked_at' => '2026-09-20 11:00:00',
            'expires_at' => '2026-09-21 11:00:00',
            'created_at' => '2026-09-20 11:00:00',
        ]);

        $this->assertSame(201, $row->responseStatus);
        $this->assertFalse($row->isInFlight());
        $this->assertSame('org_a:orders.create', $row->scope);
    }

    public function testRowWithoutStatusIsInFlight(): void
    {
        $row = IdempotencyRecord::fromArray([
            'scope' => 'org_a:orders.create',
            'key_hash' => 'kh',
            'request_hash' => 'rh',
            'response_status' => null,
        ]);

        $this->assertTrue($row->isInFlight());
    }
}
