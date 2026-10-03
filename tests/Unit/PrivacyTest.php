<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Modules\Privacy\Domain\ConsentDecision;
use Nabilet\Modules\Privacy\Domain\ConsentPolicy;
use Nabilet\Modules\Privacy\Domain\ConsentRecord;
use Nabilet\Modules\Privacy\Domain\ErasurePlan;
use Nabilet\Modules\Privacy\Domain\PrivacyDecision;
use Nabilet\Modules\Privacy\Domain\PrivacyRequest;
use Nabilet\Modules\Privacy\Domain\PrivacyRequestPolicy;
use Nabilet\Tests\Support\TestCase;

/**
 * Consent and data-subject requests (ТЗ §72, 152-ФЗ).
 *
 * The schema gaps behind these rules were reproduced against MySQL 8.4 first:
 * `consents` has no column capable of recording withdrawal and accepts a status
 * of 'banana'; a consent row may be attached to nobody; `privacy_requests` has
 * no column for a deadline and accepts an undeclared type and status.
 */
final class PrivacyTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00');
    }

    private function consent(
        string $type = 'marketing',
        string $status = ConsentRecord::GRANTED,
        ?int $userId = 7,
        ?string $anonymousId = null,
        ?\DateTimeImmutable $withdrawnAt = null,
        ?\DateTimeImmutable $grantedAt = null,
    ): ConsentRecord {
        return new ConsentRecord(
            type: $type,
            status: $status,
            grantedAt: $grantedAt ?? $this->now,
            userId: $userId,
            anonymousId: $anonymousId,
            withdrawnAt: $withdrawnAt,
        );
    }

    private function request(
        string $type = PrivacyRequest::TYPE_ACCESS,
        string $status = PrivacyRequest::REQUESTED,
        ?\DateTimeImmutable $completedAt = null,
    ): PrivacyRequest {
        return new PrivacyRequest(
            publicId: '01JPRQ00000000000000000001',
            type: $type,
            status: $status,
            createdAt: $this->now,
            completedAt: $completedAt,
        );
    }

    // ── consent: subject ─────────────────────────────────────────────────────

    public function testAConsentHasExactlyOneSubject(): void
    {
        $this->assertTrue($this->consent(userId: 7)->isGranted());
        $this->assertTrue($this->consent(userId: null, anonymousId: 'anon-1')->isGranted());
    }

    /** MySQL accepts it: both NULL. Such a row can be withdrawn by nobody. */
    public function testAConsentAttachedToNobodyIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->consent(userId: null, anonymousId: null)
        );
    }

    /** Both set is one person under two identities. */
    public function testAConsentAttachedToTwoSubjectsIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->consent(userId: 7, anonymousId: 'anon-1')
        );
    }

    // ── consent: withdrawal ──────────────────────────────────────────────────

    public function testWithdrawalRecordsItsMoment(): void
    {
        $withdrawn = $this->consent()->withdraw($this->now->modify('+3 days'));

        $this->assertSame(ConsentRecord::WITHDRAWN, $withdrawn->status);
        $this->assertNotNull($withdrawn->withdrawnAt);
        $this->assertFalse($withdrawn->isGranted());
    }

    /** "We cannot say when they withdrew" is not an acceptable answer. */
    public function testAWithdrawnConsentMustCarryAMoment(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->consent(status: ConsentRecord::WITHDRAWN, withdrawnAt: null)
        );
    }

    public function testWithdrawingTwiceIsRejected(): void
    {
        $withdrawn = $this->consent()->withdraw($this->now->modify('+1 day'));

        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $withdrawn->withdraw($this->now->modify('+2 days'))
        );
    }

    public function testAConsentCannotBeWithdrawnBeforeItWasGiven(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->consent(grantedAt: $this->now)
                ->withdraw($this->now->modify('-1 day'))
        );
    }

    public function testAnUnrecognisedStatusIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->consent(status: 'banana')
        );
    }

    // ── consent policy ───────────────────────────────────────────────────────

    /** The default is no. Absence of a record is not consent. */
    public function testNoRecordMeansNoConsent(): void
    {
        $decision = (new ConsentPolicy())->canProcess([], 'marketing');

        $this->assertFalse($decision->isPermitted());
        $this->assertSame(ConsentDecision::NO_RECORD, $decision->verdict);
    }

    /** The most common real failure: a blanket lookup treated as permission. */
    public function testConsentToAnotherPurposeIsNotConsentToThisOne(): void
    {
        $decision = (new ConsentPolicy())->canProcess(
            [$this->consent(type: 'analytics')],
            'marketing'
        );

        $this->assertFalse($decision->isPermitted());
        $this->assertSame(ConsentDecision::NOT_FOR_PURPOSE, $decision->verdict);
        $this->assertStringContainsString('one purpose is not consent to another', (string) $decision->reason);
    }

    public function testConsentForThePurposePermitsIt(): void
    {
        $decision = (new ConsentPolicy())->canProcess(
            [$this->consent(type: 'marketing'), $this->consent(type: 'analytics')],
            'marketing'
        );

        $this->assertTrue($decision->isPermitted());
    }

    public function testAWithdrawnConsentDoesNotPermit(): void
    {
        $withdrawn = $this->consent()->withdraw($this->now->modify('+1 day'));
        $decision = (new ConsentPolicy())->canProcess([$withdrawn], 'marketing');

        $this->assertFalse($decision->isPermitted());
        $this->assertSame(ConsentDecision::WITHDRAWN, $decision->verdict);
    }

    /**
     * No unique key on subject+type, so a re-grant legitimately produces a
     * second row and the history must be read newest-first.
     *
     * Note what this does NOT assert: the order of the array. The policy sorts,
     * so input order is irrelevant — an earlier draft of this test asserted that
     * it mattered, which contradicts the very behaviour under test.
     */
    public function testTheLatestConsentWins(): void
    {
        $policy = new ConsentPolicy();

        $grantedOld = $this->consent(grantedAt: $this->now->modify('-10 days'));
        $withdrawnLater = $this->consent(grantedAt: $this->now->modify('-5 days'))
            ->withdraw($this->now);

        $withdrawnOld = $this->consent(grantedAt: $this->now->modify('-10 days'))
            ->withdraw($this->now->modify('-9 days'));
        $grantedLater = $this->consent(grantedAt: $this->now->modify('-1 day'));

        // Latest is the withdrawal.
        $this->assertFalse($policy->canProcess([$grantedOld, $withdrawnLater], 'marketing')->isPermitted());
        $this->assertFalse($policy->canProcess([$withdrawnLater, $grantedOld], 'marketing')->isPermitted());

        // Latest is the re-grant.
        $this->assertTrue($policy->canProcess([$withdrawnOld, $grantedLater], 'marketing')->isPermitted());
        $this->assertTrue($policy->canProcess([$grantedLater, $withdrawnOld], 'marketing')->isPermitted());
    }

    public function testWithdrawalIsAlwaysPossibleWhileConsentStands(): void
    {
        $policy = new ConsentPolicy();

        $this->assertTrue($policy->canWithdraw($this->consent())->isPermitted());
        $this->assertFalse(
            $policy->canWithdraw($this->consent()->withdraw($this->now->modify('+1 day')))->isPermitted()
        );
    }

    // ── privacy request ──────────────────────────────────────────────────────

    public function testAnUndeclaredTypeIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->request(type: 'banana')
        );
    }

    public function testAnUndeclaredStatusIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->request(status: 'banana')
        );
    }

    public function testAClosedRequestMustRecordWhen(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->request(status: PrivacyRequest::COMPLETED)
        );
    }

    public function testARequestCannotCloseBeforeItOpened(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => $this->request(
                status: PrivacyRequest::COMPLETED,
                completedAt: $this->now->modify('-1 day')
            )
        );
    }

    /** The deadline is supplied, not assumed. */
    public function testTheDeadlineIsDerivedFromTheSuppliedPeriod(): void
    {
        $this->assertSame(
            $this->now->modify('+30 days')->getTimestamp(),
            $this->request()->dueAt(30)->getTimestamp()
        );
        $this->assertSame(
            $this->now->modify('+10 days')->getTimestamp(),
            $this->request()->dueAt(10)->getTimestamp()
        );
    }

    public function testOverdueIsMeasuredAgainstTheDeadline(): void
    {
        $request = $this->request();

        $this->assertFalse($request->isOverdue($this->now->modify('+29 days'), 30));
        $this->assertTrue($request->isOverdue($this->now->modify('+31 days'), 30));
    }

    /** A closed request is late or on time, never "overdue". */
    public function testAClosedRequestIsNeverOverdue(): void
    {
        $closed = $this->request(
            status: PrivacyRequest::COMPLETED,
            completedAt: $this->now->modify('+1 day')
        );

        $this->assertFalse($closed->isOverdue($this->now->modify('+400 days'), 30));
    }

    // ── privacy request policy ───────────────────────────────────────────────

    public function testARequestMovesForward(): void
    {
        $policy = new PrivacyRequestPolicy();

        $this->assertTrue($policy->canTransition($this->request(), PrivacyRequest::IN_PROGRESS)->isAllowed());
        $this->assertTrue($policy->canTransition($this->request(), PrivacyRequest::REJECTED)->isAllowed());
    }

    /** Reopening would restart a statutory clock without saying so. */
    public function testATerminalRequestDoesNotMove(): void
    {
        $closed = $this->request(
            status: PrivacyRequest::COMPLETED,
            completedAt: $this->now->modify('+1 day')
        );

        $decision = (new PrivacyRequestPolicy())->canTransition($closed, PrivacyRequest::IN_PROGRESS);

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(PrivacyDecision::TERMINAL, $decision->verdict);
    }

    public function testARequestDoesNotMoveBackwards(): void
    {
        $decision = (new PrivacyRequestPolicy())->canTransition(
            $this->request(status: PrivacyRequest::IN_PROGRESS),
            PrivacyRequest::REQUESTED
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(PrivacyDecision::BACKWARDS, $decision->verdict);
    }

    public function testMovingToTheSameStateWritesNothing(): void
    {
        $decision = (new PrivacyRequestPolicy())->canTransition(
            $this->request(),
            PrivacyRequest::REQUESTED
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertSame(PrivacyDecision::NO_CHANGE, $decision->verdict);
        $this->assertFalse($decision->requiresWrite());
    }

    // ── erasure ──────────────────────────────────────────────────────────────

    /** Erasure is anonymisation, not deletion, wherever money is involved. */
    public function testErasureRetainsFinancialRecordsWithThePersonDetached(): void
    {
        $plan = (new PrivacyRequestPolicy())->planErasure(
            $this->request(type: PrivacyRequest::TYPE_ERASURE),
            3
        );

        $this->assertTrue($plan->deletePersonalData);
        $this->assertTrue($plan->retainFinancialRecords);
        $this->assertTrue($plan->detachHolderNames);
        $this->assertSame(3, $plan->ordersToAnonymize);
        $this->assertFalse($plan->isPlainDeletion());
    }

    public function testErasureWithoutFinancialRecordsIsPlainDeletion(): void
    {
        $plan = ErasurePlan::forSubject(0);

        $this->assertTrue($plan->isPlainDeletion());
        $this->assertSame(0, $plan->ordersToAnonymize);
    }

    public function testOnlyAnErasureRequestHasAnErasurePlan(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => (new PrivacyRequestPolicy())->planErasure($this->request(), 1)
        );
    }

    public function testANegativeErasureScopeIsRejected(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => (new PrivacyRequestPolicy())->planErasure(
                $this->request(type: PrivacyRequest::TYPE_ERASURE),
                -1
            )
        );
    }
}
