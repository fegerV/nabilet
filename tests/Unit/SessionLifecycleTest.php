<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Auth\Domain\SessionDecision;
use Nabilet\Modules\Auth\Domain\SessionPolicy;
use Nabilet\Modules\Auth\Domain\UserSession;
use Nabilet\Tests\Support\TestCase;

/**
 * The lifecycle of a login session (ТЗ §5, §6).
 *
 * Reproduced against MySQL 8.4 first, in `tools/repro-session-lifecycle.sql`:
 *
 *   - `user_sessions` with `expires_at = NULL` was accepted — the column is
 *     nullable, so a session can be unbounded;
 *   - a row expiring an hour BEFORE its own `created_at` was accepted;
 *   - a row with `last_seen_at` three days AFTER `expires_at` was accepted;
 *   - a count of columns able to record a revocation returned **0**, and
 *     `UPDATE user_sessions SET revoked_at = …` failed with `ERROR 1054`;
 *   - so ending one device is a DELETE, and the row is the only record.
 */
final class SessionLifecycleTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-01 12:00:00');
    }

    /**
     * `$expiresAt` defaults to '+1 hour'; pass null explicitly for the unbounded
     * row MySQL accepted. A plain `?\DateTimeImmutable $expiresAt = null` with a
     * `?? default` would swallow the explicit null and turn every unbounded case
     * into a bounded one — which is exactly the mistake this suite exists to catch.
     */
    private function session(
        \DateTimeImmutable|string|null $expiresAt = '+1 hour',
        ?\DateTimeImmutable $lastSeenAt = null,
        ?\DateTimeImmutable $createdAt = null,
        int|string $id = 1,
    ): UserSession {
        $resolved = match (true) {
            $expiresAt === null => null,
            $expiresAt instanceof \DateTimeImmutable => $expiresAt,
            default => $this->now->modify($expiresAt),
        };

        return new UserSession(
            id: $id,
            userId: 100,
            tokenHash: str_repeat('a', 64),
            createdAt: $createdAt ?? $this->now->modify('-1 hour'),
            expiresAt: $resolved,
            lastSeenAt: $lastSeenAt,
        );
    }

    private function policy(): SessionPolicy
    {
        return new SessionPolicy();
    }

    // ── the row ─────────────────────────────────────────────────────────────

    public function testASessionWithAnExpiryIsBounded(): void
    {
        $session = $this->session();

        $this->assertFalse($session->isImmortal());
        $this->assertFalse($session->isExpiredAt($this->now));
        $this->assertSame(3600, $session->remainingSecondsAt($this->now));
    }

    public function testASessionWithoutAnExpiryIsImmortal(): void
    {
        // Accepted by MySQL: expires_at is nullable.
        $session = $this->session(expiresAt: null);

        $this->assertTrue($session->isImmortal());
        $this->assertFalse($session->isExpiredAt($this->now->modify('+10 years')));
        $this->assertNull($session->remainingSecondsAt($this->now));
    }

    public function testAnExpiryBeforeCreationIsDetectable(): void
    {
        // Accepted by MySQL: expires_at an hour before created_at.
        $session = $this->session(
            expiresAt: $this->now->modify('-2 hours'),
            createdAt: $this->now->modify('-1 hour'),
        );

        $this->assertTrue($session->expiresBeforeItWasCreated());
        $this->assertTrue($session->isExpiredAt($this->now));
    }

    public function testUseAfterExpiryIsDetectable(): void
    {
        // Accepted by MySQL: last_seen_at three days after expires_at.
        $session = $this->session(
            expiresAt: $this->now->modify('-3 days'),
            lastSeenAt: $this->now,
            createdAt: $this->now->modify('-4 days'),
        );

        $this->assertTrue($session->usedAfterExpiry());
    }

    public function testUseInsideTheWindowIsNotAfterExpiry(): void
    {
        $session = $this->session(
            expiresAt: $this->now->modify('+1 hour'),
            lastSeenAt: $this->now,
        );

        $this->assertFalse($session->usedAfterExpiry());
    }

    public function testIdleTimeIsReportedAndNotDecided(): void
    {
        // The TTL and the idle timeout are policy numbers nobody has fixed, so the
        // object reports the fact and the caller applies the configuration.
        $session = $this->session(lastSeenAt: $this->now->modify('-15 minutes'));

        $this->assertSame(900, $session->idleSecondsAt($this->now));
        $this->assertTrue($this->session()->isUnused());
        $this->assertNull($this->session()->idleSecondsAt($this->now));
    }

    // ── issuing ─────────────────────────────────────────────────────────────

    public function testABoundedSessionCanBeIssued(): void
    {
        $this->assertTrue($this->policy()->issueDecision($this->session())->isAllowed());
    }

    public function testAnUnboundedSessionCannotBeIssued(): void
    {
        $decision = $this->policy()->issueDecision($this->session(expiresAt: null));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SessionDecision::NO_EXPIRY, $decision->verdict);
    }

    public function testASessionCannotExpireBeforeItWasCreated(): void
    {
        $decision = $this->policy()->issueDecision(
            $this->session(expiresAt: $this->now->modify('-2 hours'))
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SessionDecision::EXPIRES_BEFORE_CREATED, $decision->verdict);
    }

    // ── authenticating ──────────────────────────────────────────────────────

    public function testALiveSessionIsHonoured(): void
    {
        $this->assertTrue(
            $this->policy()->authenticateDecision($this->session(), $this->now)->isAllowed()
        );
    }

    public function testAnExpiredSessionIsRefused(): void
    {
        $decision = $this->policy()->authenticateDecision(
            $this->session(expiresAt: $this->now->modify('-1 second')),
            $this->now
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SessionDecision::EXPIRED, $decision->verdict);
    }

    public function testAnUnboundedSessionIsRefusedRatherThanTreatedAsValid(): void
    {
        // Fails closed, the same choice as consent without a record and an
        // unrecognised cart status: "unbounded" is not "valid".
        $decision = $this->policy()->authenticateDecision(
            $this->session(expiresAt: null),
            $this->now
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SessionDecision::NO_EXPIRY, $decision->verdict);
    }

    public function testTheMomentOfExpiryIsNotHonoured(): void
    {
        // Boundary: equality is expired. A token valid "until" T is not valid at T.
        $decision = $this->policy()->authenticateDecision(
            $this->session(expiresAt: $this->now),
            $this->now
        );

        $this->assertSame(SessionDecision::EXPIRED, $decision->verdict);
    }

    // ── auditing rows that predate the rule ─────────────────────────────────

    public function testTheAuditPassesAnOrdinarySession(): void
    {
        $this->assertTrue(
            $this->policy()->auditDecision($this->session(lastSeenAt: $this->now), $this->now)->isAllowed()
        );
    }

    public function testTheAuditFindsTheRowUsedAfterExpiry(): void
    {
        $decision = $this->policy()->auditDecision(
            $this->session(
                expiresAt: $this->now->modify('-3 days'),
                lastSeenAt: $this->now,
                createdAt: $this->now->modify('-4 days'),
            ),
            $this->now
        );

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(SessionDecision::USED_AFTER_EXPIRY, $decision->verdict);
        $this->assertStringContainsString('replayed', (string) $decision->reason);
    }

    public function testTheAuditReportsAnIncoherentTimelineAheadOfExpiry(): void
    {
        // Both faults at once: expires before created AND used after expiry. The
        // timeline is the defect; "expired" would be a misleadingly normal answer.
        $decision = $this->policy()->auditDecision(
            $this->session(
                expiresAt: $this->now->modify('-2 hours'),
                lastSeenAt: $this->now,
                createdAt: $this->now->modify('-1 hour'),
            ),
            $this->now
        );

        $this->assertSame(SessionDecision::EXPIRES_BEFORE_CREATED, $decision->verdict);
    }

    public function testTheAuditFindsAnUnboundedSession(): void
    {
        $decision = $this->policy()->auditDecision($this->session(expiresAt: null), $this->now);

        $this->assertSame(SessionDecision::NO_EXPIRY, $decision->verdict);
    }

    // ── ending a session ────────────────────────────────────────────────────

    public function testRevocationIsPossibleButOnlyByDelete(): void
    {
        // There is no revoked_at column — the count on MySQL was 0 and the UPDATE
        // failed with 1054. So the write is a DELETE, and the audit must come first.
        $decision = $this->policy()->revocationDecision($this->session());

        $this->assertTrue($decision->isAllowed());
        $this->assertTrue($decision->requiresDelete());
        $this->assertSame(SessionDecision::REVOKABLE_ONLY_BY_DELETE, $decision->verdict);
        $this->assertStringContainsString('login_logs', (string) $decision->reason);
    }

    public function testRevocationStillAppliesToAnExpiredSession(): void
    {
        // An expired row is still a credential-shaped artefact; deleting it is how
        // "log out everywhere" works.
        $decision = $this->policy()->revocationDecision(
            $this->session(expiresAt: $this->now->modify('-1 day'))
        );

        $this->assertTrue($decision->isAllowed());
        $this->assertTrue($decision->requiresDelete());
    }
}
