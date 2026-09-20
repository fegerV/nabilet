<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Security\Domain\ApiKey;
use Nabilet\Modules\Security\Domain\ApiKeyDecision;
use Nabilet\Modules\Security\Domain\ApiKeyPolicy;
use Nabilet\Modules\Security\Domain\IpRule;
use Nabilet\Modules\Security\Domain\IpRuleDecision;
use Nabilet\Modules\Security\Domain\IpRulePolicy;
use Nabilet\Tests\Support\TestCase;

/**
 * API keys and IP rules (ТЗ §78, §79).
 *
 * Reproduced against MySQL 8.4 first, in `tools/repro-api-key-rules.sql`:
 *
 *   - an `api_keys` row with `organization_id = NULL` was accepted;
 *   - a key with no `expires_at` was accepted, and so was one with `scopes_json`
 *     NULL, and one with `revoked_at` a year in the future;
 *   - `UPDATE api_keys SET revoked_at = NULL` was accepted — an un-revocation with
 *     no trace, because there is no history column and a count of columns able to
 *     record last use returned 0;
 *   - `ip_rules` accepted a rule with neither an IP nor a CIDR, a rule with both,
 *     `rule_type = 'banana'`, a rule expired a year ago with `active = 1`, and two
 *     contradicting rules for the same address.
 */
final class SecurityPolicyTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function key(
        ?int $organizationId = 1,
        array|string|null $scopes = ['events.read'],
        ?\DateTimeImmutable $expiresAt = null,
        ?\DateTimeImmutable $revokedAt = null,
        int|string $id = 1,
    ): ApiKey {
        if ($scopes === 'null') {
            $scopes = null;
        }

        return new ApiKey(
            id: $id,
            publicId: 'apk_aaaaaaaaaaaaaaaaaaaaaa',
            organizationId: $organizationId,
            name: 'key',
            keyPrefix: 'nb_live_',
            keyHash: str_repeat('a', 64),
            createdAt: $this->now->modify('-1 day'),
            scopes: $scopes,
            expiresAt: $expiresAt ?? $this->now->modify('+30 days'),
            revokedAt: $revokedAt,
        );
    }

    private function keys(): ApiKeyPolicy
    {
        return new ApiKeyPolicy();
    }

    private function rule(
        string $ruleType = IpRule::DENY,
        ?string $ipAddress = null,
        ?string $cidr = null,
        bool $active = true,
        ?\DateTimeImmutable $expiresAt = null,
        int|string $id = 1,
    ): IpRule {
        return new IpRule(
            id: $id,
            ruleType: $ruleType,
            active: $active,
            ipAddress: $ipAddress,
            cidr: $cidr,
            expiresAt: $expiresAt,
        );
    }

    private function rules(): IpRulePolicy
    {
        return new IpRulePolicy();
    }

    // ── the key ──────────────────────────────────────────────────────────────

    public function testARevocationIsARevocationEvenDatedAhead(): void
    {
        // MySQL accepted revoked_at a year in the future. Reading it as "not yet"
        // would leave a key that dies silently at a moment nobody is watching.
        $key = $this->key(revokedAt: $this->now->modify('+1 year'));

        $this->assertTrue($key->isRevoked());
        $this->assertTrue($key->revokedInTheFuture($this->now));
    }

    public function testScopesDistinguishNullFromEmpty(): void
    {
        $this->assertTrue($this->key()->hasScopes());
        $this->assertFalse($this->key(scopes: 'null')->hasScopes());
        $this->assertFalse($this->key(scopes: [])->hasScopes());
    }

    public function testAnUnboundedKeyIsImmortal(): void
    {
        $key = new ApiKey(
            id: 2,
            publicId: 'apk_bbbbbbbbbbbbbbbbbbbbbb',
            organizationId: 1,
            name: 'forever',
            keyPrefix: 'nb_live_',
            keyHash: str_repeat('b', 64),
            createdAt: $this->now,
        );

        $this->assertTrue($key->isImmortal());
        $this->assertNull($key->remainingSecondsAt($this->now));
    }

    public function testAGrantedScopeIsGrantedAndOthersAreNot(): void
    {
        $key = $this->key(scopes: ['events.read', 'orders.write']);

        $this->assertTrue($key->grants('orders.write'));
        $this->assertFalse($key->grants('orders.refund'));
    }

    // ── using a key ──────────────────────────────────────────────────────────

    public function testALiveKeyWithScopesIsUsable(): void
    {
        $decision = $this->keys()->useDecision($this->key(), $this->now);

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(ApiKeyDecision::ALLOWED, $decision->verdict);
    }

    public function testARevokedKeyIsRefused(): void
    {
        $decision = $this->keys()->useDecision($this->key(revokedAt: $this->now), $this->now);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(ApiKeyDecision::REVOKED, $decision->verdict);
    }

    public function testAnUnboundedKeyIsRefused(): void
    {
        $decision = $this->keys()->useDecision(
            new ApiKey(
                id: 2,
                publicId: 'apk_bbbbbbbbbbbbbbbbbbbbbb',
                organizationId: 1,
                name: 'forever',
                keyPrefix: 'nb_live_',
                keyHash: str_repeat('b', 64),
                createdAt: $this->now,
                scopes: ['events.read'],
            ),
            $this->now
        );

        $this->assertSame(ApiKeyDecision::NO_EXPIRY, $decision->verdict);
    }

    public function testAnExpiredKeyIsRefused(): void
    {
        $decision = $this->keys()->useDecision(
            $this->key(expiresAt: $this->now->modify('-1 second')),
            $this->now
        );

        $this->assertSame(ApiKeyDecision::EXPIRED, $decision->verdict);
    }

    public function testTheMomentOfExpiryIsNotUsable(): void
    {
        // Boundary: equality is expired.
        $decision = $this->keys()->useDecision($this->key(expiresAt: $this->now), $this->now);

        $this->assertSame(ApiKeyDecision::EXPIRED, $decision->verdict);
    }

    public function testAKeyWithNullScopesIsRefusedRatherThanReadAsUnrestricted(): void
    {
        // NULL is two-valued. Fail closed on the reading that would be
        // catastrophic if the other one was meant.
        $decision = $this->keys()->useDecision($this->key(scopes: 'null'), $this->now);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(ApiKeyDecision::NO_SCOPES, $decision->verdict);
        $this->assertStringContainsString('NULL', (string) $decision->reason);
    }

    public function testRevocationOutranksExpiryAndScopes(): void
    {
        // An explicit human act outranks a clock; the clock outranks what the key
        // may do. All three faults at once, and revocation must be the answer.
        $decision = $this->keys()->useDecision(
            $this->key(scopes: 'null', expiresAt: $this->now->modify('-1 hour'), revokedAt: $this->now),
            $this->now
        );

        $this->assertSame(ApiKeyDecision::REVOKED, $decision->verdict);
    }

    public function testASpecificScopeIsCheckedOnlyAfterTheKeyIsAlive(): void
    {
        $this->assertTrue(
            $this->keys()->scopeDecision($this->key(), 'events.read', $this->now)->isAllowed()
        );

        $this->assertSame(
            ApiKeyDecision::SCOPE_NOT_GRANTED,
            $this->keys()->scopeDecision($this->key(), 'orders.refund', $this->now)->verdict
        );

        // A revoked key reports the revocation, not the missing scope.
        $this->assertSame(
            ApiKeyDecision::REVOKED,
            $this->keys()->scopeDecision($this->key(revokedAt: $this->now), 'events.read', $this->now)->verdict
        );
    }

    // ── issuing and revoking ─────────────────────────────────────────────────

    public function testAWellFormedKeyCanBeIssued(): void
    {
        $this->assertTrue($this->keys()->issueDecision($this->key(), $this->now)->isAllowed());
    }

    public function testAKeyWithNoOrganizationCannotBeIssued(): void
    {
        // Accepted by MySQL. In a schema where only 16 of 64 tables know about
        // tenancy, "no organization" is not a tenant.
        $decision = $this->keys()->issueDecision($this->key(organizationId: null), $this->now);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(ApiKeyDecision::NO_ORGANIZATION, $decision->verdict);
    }

    public function testAKeyThatWouldGrantNothingCannotBeIssued(): void
    {
        $this->assertSame(
            ApiKeyDecision::NO_SCOPES,
            $this->keys()->issueDecision($this->key(scopes: []), $this->now)->verdict
        );
    }

    public function testRevokingAnAlreadyRevokedKeyIsNoChange(): void
    {
        $decision = $this->keys()->revokeDecision($this->key(revokedAt: $this->now));

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(ApiKeyDecision::NO_CHANGE, $decision->verdict);
        $this->assertFalse($decision->requiresWrite());
    }

    public function testRevokingALiveKeyIsWork(): void
    {
        $decision = $this->keys()->revokeDecision($this->key());

        $this->assertTrue($decision->requiresWrite());
    }

    public function testUnRevokingIsRefused(): void
    {
        // MySQL accepted revoked_at back to NULL. There is no history column and a
        // count of last-use columns returned 0, so the act would leave no trace.
        $decision = $this->keys()->unrevokeDecision($this->key(revokedAt: $this->now));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(ApiKeyDecision::REVOCATION_IS_TERMINAL, $decision->verdict);
        $this->assertStringContainsString('new key', (string) $decision->reason);
    }

    public function testTheAuditFindsARevocationDatedAhead(): void
    {
        $decision = $this->keys()->auditDecision($this->key(revokedAt: $this->now->modify('+1 year')), $this->now);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(ApiKeyDecision::REVOKED_IN_THE_FUTURE, $decision->verdict);
    }

    public function testTheAuditFindsAnOrphanKey(): void
    {
        $decision = $this->keys()->auditDecision($this->key(organizationId: null), $this->now);

        $this->assertSame(ApiKeyDecision::NO_ORGANIZATION, $decision->verdict);
    }

    public function testTheAuditPassesAnOrdinaryKey(): void
    {
        $this->assertTrue($this->keys()->auditDecision($this->key(), $this->now)->isAllowed());
    }

    // ── ip rules ─────────────────────────────────────────────────────────────

    public function testARuleWithOneTargetIsReadable(): void
    {
        $this->assertTrue($this->rule(ipAddress: '10.0.0.1')->isReadable());
        $this->assertTrue($this->rule(cidr: '10.0.0.0/24')->isReadable());
    }

    public function testARuleWithNoTargetIsNotReadable(): void
    {
        // Accepted by MySQL. A deny rule with no target blocks nobody — or
        // everybody, depending on which side of the code reads it.
        $this->assertFalse($this->rule()->isReadable());
        $this->assertFalse($this->rule()->matches('10.0.0.1'));
    }

    public function testARuleWithTwoTargetsIsNotReadable(): void
    {
        $this->assertFalse($this->rule(ipAddress: '10.0.0.1', cidr: '192.168.0.0/24')->isReadable());
    }

    public function testAnUnknownRuleTypeIsNotReadable(): void
    {
        // 'banana' was accepted by MySQL.
        $this->assertFalse($this->rule(ruleType: 'banana', ipAddress: '10.0.0.1')->isReadable());
    }

    public function testAnExactAddressMatches(): void
    {
        $rule = $this->rule(ipAddress: '10.0.0.1');

        $this->assertTrue($rule->matches('10.0.0.1'));
        $this->assertFalse($rule->matches('10.0.0.2'));
    }

    public function testACidrMatchesItsRange(): void
    {
        $rule = $this->rule(cidr: '10.0.0.0/24');

        $this->assertTrue($rule->matches('10.0.0.0'));
        $this->assertTrue($rule->matches('10.0.0.255'));
        $this->assertFalse($rule->matches('10.0.1.1'));
    }

    public function testACidrWithANonByteAlignedMaskMatches(): void
    {
        // /12 is not a whole number of bytes — the partial byte is where this
        // kind of code is usually wrong.
        $rule = $this->rule(cidr: '10.0.0.0/12');

        $this->assertTrue($rule->matches('10.0.0.1'));
        $this->assertTrue($rule->matches('10.15.255.255'));
        $this->assertFalse($rule->matches('10.16.0.1'));
    }

    public function testAMalformedCidrMatchesNothing(): void
    {
        $this->assertFalse($this->rule(cidr: '10.0.0.0/99')->matches('10.0.0.1'));
        $this->assertFalse($this->rule(cidr: 'not-a-cidr/24')->matches('10.0.0.1'));
        $this->assertFalse($this->rule(cidr: '10.0.0.0')->matches('10.0.0.1'));
    }

    public function testAAllowingRuleAndADenyingRuleAreDistinguished(): void
    {
        $this->assertTrue($this->rule(IpRule::ALLOW, ipAddress: '10.0.0.1')->isAllow());
        $this->assertFalse($this->rule(IpRule::ALLOW, ipAddress: '10.0.0.1')->isDeny());
    }

    // ── evaluating a ruleset ─────────────────────────────────────────────────

    public function testADenyRuleBlocksTheAddress(): void
    {
        $decision = $this->rules()->evaluate(
            '10.0.0.1',
            [$this->rule(ipAddress: '10.0.0.1')],
            $this->now,
            true
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IpRuleDecision::DENY, $decision->verdict);
    }

    public function testDenyBeatsAllowRegardlessOfOrder(): void
    {
        // If an allow could outrank a deny, one broad allow row would quietly
        // retire every block in the table. Accepted by MySQL: both rows.
        $deny = $this->rule(ipAddress: '10.0.0.2', id: 4);
        $allow = $this->rule(IpRule::ALLOW, ipAddress: '10.0.0.2', id: 5);

        $this->assertSame(IpRuleDecision::DENY, $this->rules()->evaluate('10.0.0.2', [$allow, $deny], $this->now, true)->verdict);
        $this->assertSame(IpRuleDecision::DENY, $this->rules()->evaluate('10.0.0.2', [$deny, $allow], $this->now, true)->verdict);
    }

    public function testAnAddressNoRuleCoversAbstainsToTheCallersDefault(): void
    {
        // The table mixes allow and deny rows and records nowhere what happens
        // when nothing matches, so the default must be supplied.
        $this->assertTrue($this->rules()->evaluate('10.9.9.9', [$this->rule(ipAddress: '10.0.0.1')], $this->now, true)->isAllowed());
        $this->assertFalse($this->rules()->evaluate('10.9.9.9', [$this->rule(ipAddress: '10.0.0.1')], $this->now, false)->isAllowed());

        $decision = $this->rules()->evaluate('10.9.9.9', [$this->rule(ipAddress: '10.0.0.1')], $this->now, true);
        $this->assertTrue($decision->isAbstain());
    }

    public function testAnUnreadableRuleMakesTheRulesetRefuseToEvaluate(): void
    {
        // Fails closed: one malformed row blocks traffic until it is fixed, which
        // is loud. Ignoring it would silently disable a blocklist.
        $decision = $this->rules()->evaluate(
            '10.0.0.1',
            [$this->rule(ipAddress: '10.0.0.1'), $this->rule(id: 7)],
            $this->now,
            true
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IpRuleDecision::INCOHERENT_RULESET, $decision->verdict);
        $this->assertStringContainsString('7', (string) $decision->reason);
    }

    public function testAnInactiveRuleDoesNotTakePart(): void
    {
        $decision = $this->rules()->evaluate(
            '10.0.0.1',
            [$this->rule(ipAddress: '10.0.0.1', active: false)],
            $this->now,
            true
        );

        $this->assertTrue($decision->isAbstain());
    }

    public function testAnExpiredRuleDoesNotTakePartWhetherOrNotItIsActive(): void
    {
        // MySQL accepted expires_at a year in the past with active = 1.
        $decision = $this->rules()->evaluate(
            '10.0.0.1',
            [$this->rule(ipAddress: '10.0.0.1', expiresAt: $this->now->modify('-1 year'))],
            $this->now,
            true
        );

        $this->assertTrue($decision->isAbstain());
    }

    public function testThePolicyRefusesForeignRuleObjects(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->rules()->evaluate('10.0.0.1', [new \stdClass()], $this->now, true),
            'IpRulePolicy must reject foreign rules'
        );
    }

    // ── auditing rules ───────────────────────────────────────────────────────

    public function testTheAuditPassesAnOrdinaryRule(): void
    {
        $this->assertTrue($this->rules()->auditDecision($this->rule(ipAddress: '10.0.0.1'), $this->now)->isAllowed());
    }

    public function testTheAuditFindsARuleWithNoTarget(): void
    {
        $this->assertSame(
            IpRuleDecision::NO_TARGET,
            $this->rules()->auditDecision($this->rule(id: 1), $this->now)->verdict
        );
    }

    public function testTheAuditFindsARuleWithTwoTargets(): void
    {
        $this->assertSame(
            IpRuleDecision::BOTH_TARGETS,
            $this->rules()->auditDecision($this->rule(ipAddress: '10.0.0.1', cidr: '192.168.0.0/24'), $this->now)->verdict
        );
    }

    public function testTheAuditFindsAnUnknownType(): void
    {
        $this->assertSame(
            IpRuleDecision::UNKNOWN_TYPE,
            $this->rules()->auditDecision($this->rule(ruleType: 'banana', ipAddress: '10.0.0.1'), $this->now)->verdict
        );
    }

    public function testTheAuditFindsARuleExpiredButStillActive(): void
    {
        $decision = $this->rules()->auditDecision(
            $this->rule(ipAddress: '10.0.0.2', expiresAt: $this->now->modify('-1 year')),
            $this->now
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IpRuleDecision::EXPIRED_BUT_ACTIVE, $decision->verdict);
    }

    public function testAnExpiredInactiveRuleIsClean(): void
    {
        // Not a defect: someone turned it off. Retroactively expiring an inactive
        // rule is ordinary housekeeping.
        $this->assertTrue(
            $this->rules()->auditDecision(
                $this->rule(ipAddress: '10.0.0.2', active: false, expiresAt: $this->now->modify('-1 year')),
                $this->now
            )->isAllowed()
        );
    }
}
