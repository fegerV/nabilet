<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Domain;

/**
 * The lifecycle of a login session (ТЗ §5, §6).
 *
 * Every gap these rules close was reproduced against MySQL 8.4 first, in
 * `tools/repro-session-lifecycle.sql`; they are collected in REVIEW §3.18.
 *
 * WHAT IS DELIBERATELY NOT DECIDED HERE
 *   Idle timeout and absolute TTL are policy numbers, and neither the ТЗ nor the
 *   contract fixes them — so they are not constants. `UserSession::idleSecondsAt()`
 *   reports the fact and the caller applies whatever the configuration says. The
 *   same call that was made about the privacy SLA and the service fee.
 *
 *   There is no rule about how many sessions a user may have. Nothing in the
 *   schema suggests a limit, and inventing one would be a product decision.
 */
final class SessionPolicy
{
    /**
     * May this session be issued at all?
     *
     * Both checks are about the row being coherent, not about the user: a session
     * that cannot expire, or one whose expiry precedes its own creation, is
     * unenforceable whichever way the caller later reads it.
     */
    public function issueDecision(UserSession $session): SessionDecision
    {
        // expires_at is NULLable and NULL was accepted on MySQL 8.4.
        if ($session->isImmortal()) {
            return SessionDecision::denied(
                SessionDecision::NO_EXPIRY,
                sprintf(
                    'Session %s has no expires_at: nothing bounds it, so a stolen token '
                    . 'stays valid after a password change and after the incident is closed.',
                    (string) $session->id
                )
            );
        }

        // Accepted on MySQL 8.4: expires_at an hour BEFORE created_at.
        if ($session->expiresBeforeItWasCreated()) {
            return SessionDecision::denied(
                SessionDecision::EXPIRES_BEFORE_CREATED,
                sprintf(
                    'Session %s expires at %s but was created at %s; code reading '
                    . 'expires_at and code reading created_at would disagree forever.',
                    (string) $session->id,
                    $session->expiresAt?->format('c') ?? '—',
                    $session->createdAt->format('c')
                )
            );
        }

        return SessionDecision::allowed();
    }

    /**
     * May this token still be used?
     *
     * A session with no expiry is refused rather than treated as "still valid".
     * That fails closed, which is the same choice made for consent without a record
     * and for an unrecognised cart status: a session that cannot expire cannot be
     * bounded, and treating "unbounded" as "valid" would make the expiry column
     * optional in practice. The consequence is honest and worth stating — existing
     * unbounded rows stop working, which is the point.
     */
    public function authenticateDecision(UserSession $session, \DateTimeImmutable $now): SessionDecision
    {
        if ($session->isImmortal()) {
            return SessionDecision::denied(
                SessionDecision::NO_EXPIRY,
                sprintf('Session %s has no expiry and cannot be honoured.', (string) $session->id)
            );
        }

        if ($session->isExpiredAt($now)) {
            return SessionDecision::denied(
                SessionDecision::EXPIRED,
                sprintf(
                    'Session %s expired at %s.',
                    (string) $session->id,
                    $session->expiresAt?->format('c') ?? '—'
                )
            );
        }

        return SessionDecision::allowed();
    }

    /**
     * Audit a row that already exists — the only way to find the ones written
     * before these rules, or written by something that bypassed them.
     *
     * The timeline checks come before the expiry check on purpose: an incoherent
     * row is a defect in the data, and "expired" would be a misleadingly normal
     * answer for it.
     */
    public function auditDecision(UserSession $session, \DateTimeImmutable $now): SessionDecision
    {
        if ($session->isImmortal()) {
            return SessionDecision::denied(
                SessionDecision::NO_EXPIRY,
                sprintf('Session %s is unbounded: it will never expire.', (string) $session->id)
            );
        }

        if ($session->expiresBeforeItWasCreated()) {
            return SessionDecision::denied(
                SessionDecision::EXPIRES_BEFORE_CREATED,
                sprintf('Session %s expires before it was created.', (string) $session->id)
            );
        }

        if ($session->usedAfterExpiry()) {
            return SessionDecision::denied(
                SessionDecision::USED_AFTER_EXPIRY,
                sprintf(
                    'Session %s was last seen at %s, after it stopped being honoured at %s: '
                    . 'either expiry is not enforced anywhere, or the token is being replayed.',
                    (string) $session->id,
                    $session->lastSeenAt?->format('c') ?? '—',
                    $session->expiresAt?->format('c') ?? '—'
                )
            );
        }

        if ($session->isExpiredAt($now)) {
            return SessionDecision::denied(
                SessionDecision::EXPIRED,
                sprintf('Session %s has expired.', (string) $session->id)
            );
        }

        return SessionDecision::allowed();
    }

    /**
     * How a session gets ended.
     *
     * `user_sessions` has no revocation column — proven on MySQL 8.4, where the
     * count of columns able to record one was 0 and `UPDATE … SET revoked_at`
     * failed with 1054. So ending one device is a DELETE, and the row that would
     * have explained the logout is the row being destroyed.
     *
     * This returns ALLOWED with a distinct verdict, because refusing would be a
     * lie — the session can be ended — but the caller must write the audit line
     * first, into `login_logs`, which does exist.
     */
    public function revocationDecision(UserSession $session): SessionDecision
    {
        return SessionDecision::revokableByDelete(sprintf(
            'Session %s can only be ended by deleting the row: user_sessions has no revoked_at. '
            . 'Write the audit entry (login_logs) BEFORE the DELETE, because the row is the '
            . 'only record that the session existed.',
            (string) $session->id
        ));
    }
}
