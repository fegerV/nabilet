<?php

declare(strict_types=1);

namespace Nabilet\Modules\Users\Domain;

/**
 * The lifecycle of an account's identity (ТЗ §5, §6; 152-ФЗ через §3.15).
 *
 * Every rule here closes a gap reproduced against MySQL 8.4 first, in
 * `tools/repro-user-identity.sql`; they are collected in REVIEW §3.17.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *   `users.status` has no CHECK and no agreed vocabulary, so the status is never
 *   validated — only `deleted_at` is used as the deletion marker, because that is
 *   the column that exists. Inventing a status vocabulary would be a decision the
 *   spec bundle has not made (§3.11).
 *
 *   Password strength, rate limits and session handling belong elsewhere; this
 *   policy answers only "is this identity usable, and may it take this address".
 */
final class UserIdentityPolicy
{
    /**
     * May this identity be created?
     *
     * `$emailOccupied` / `$occupantDeleted` are supplied by the caller because the
     * unique index is the only thing that knows, and asking it is I/O — which the
     * domain must not do. The caller answers one question, not two: whether the
     * address is taken, and whether the row taking it is a tombstone.
     */
    public function registerDecision(
        UserIdentity $candidate,
        bool $emailOccupied,
        bool $occupantDeleted = false,
    ): IdentityDecision {
        // An account nobody can reach is worse than no account: it can hold orders
        // and tickets that its owner can never see or recover.
        if (! $candidate->isReachable()) {
            return IdentityDecision::denied(
                IdentityDecision::NOT_REACHABLE,
                sprintf(
                    'Account %s has neither an email nor a phone: it cannot be logged into, '
                    . 'sent a ticket, or password-reset.',
                    (string) $candidate->id
                )
            );
        }

        if ($candidate->normalisedEmail() !== null && $emailOccupied) {
            if ($occupantDeleted) {
                return IdentityDecision::denied(
                    IdentityDecision::EMAIL_TAKEN_BY_DELETED,
                    sprintf(
                        'The address is held by a DELETED account. uq_users_email does not '
                        . 'include deleted_at, so the tombstone keeps the address forever: '
                        . 'purge or anonymise it before this address can be used again.',
                    )
                );
            }

            return IdentityDecision::denied(
                IdentityDecision::EMAIL_TAKEN,
                'The address is already registered to a live account.'
            );
        }

        if ($candidate->orphanedVerifications() !== []) {
            return IdentityDecision::denied(
                IdentityDecision::ORPHANED_VERIFICATION,
                sprintf(
                    'Account %s claims a verified %s with no contact behind it.',
                    (string) $candidate->id,
                    implode(' and ', $candidate->orphanedVerifications())
                )
            );
        }

        return IdentityDecision::allowed();
    }

    /** May this identity be used to log in? */
    public function authenticateDecision(UserIdentity $identity): IdentityDecision
    {
        if ($identity->isDeleted()) {
            return IdentityDecision::denied(
                IdentityDecision::ACCOUNT_DELETED,
                sprintf(
                    'Account %s was deleted at %s; a deleted account may not authenticate.',
                    (string) $identity->id,
                    $identity->deletedAt?->format('c') ?? '—'
                )
            );
        }

        if (! $identity->isReachable()) {
            return IdentityDecision::denied(
                IdentityDecision::NOT_REACHABLE,
                sprintf('Account %s has no way to be reached, so it cannot be authenticated.', (string) $identity->id)
            );
        }

        return IdentityDecision::allowed();
    }

    /**
     * Does every verification flag on this account still vouch for something?
     *
     * This is the audit for rows written before the rule — the same role
     * `CartPolicy::compositionDecision()` plays for carts.
     */
    public function verificationDecision(UserIdentity $identity): IdentityDecision
    {
        $orphans = $identity->orphanedVerifications();

        if ($orphans !== []) {
            return IdentityDecision::denied(
                IdentityDecision::ORPHANED_VERIFICATION,
                sprintf(
                    'Account %s claims a verified %s with no contact behind it: the flag '
                    . 'outlived the address it was granted for.',
                    (string) $identity->id,
                    implode(' and ', $orphans)
                )
            );
        }

        return IdentityDecision::allowed();
    }

    /**
     * May this address be moved onto this account?
     *
     * The caller is expected to apply the move with `withEmail()`, which clears the
     * verification flag when the address actually changes. This method is the check
     * performed BEFORE the write, and it refuses the one case where the flag would
     * otherwise survive: a target address that differs from the current one while
     * the account is still marked verified.
     */
    public function addressChangeDecision(UserIdentity $identity, ?string $newEmail): IdentityDecision
    {
        $next = ContactChannel::normalise(ContactChannel::EMAIL, $newEmail);

        // Removing the last contact is worse than an unverified one: the account
        // becomes unreachable, so nothing can ever be delivered to it or reset on it.
        if ($next === null && $identity->normalisedPhone() === null) {
            return IdentityDecision::denied(
                IdentityDecision::NOT_REACHABLE,
                sprintf(
                    'Removing the email from account %s would leave it with no way to be reached.',
                    (string) $identity->id
                )
            );
        }

        if ($identity->emailVerifiedAt !== null && $next !== $identity->normalisedEmail()) {
            return IdentityDecision::denied(
                IdentityDecision::ORPHANED_VERIFICATION,
                sprintf(
                    'Account %s is verified for a different address; the move clears '
                    . 'email_verified_at and the account must confirm the new one.',
                    (string) $identity->id
                )
            );
        }

        return IdentityDecision::allowed();
    }
}
