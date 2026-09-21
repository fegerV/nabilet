<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Domain;

/**
 * Decides whether personal data may be processed for a purpose.
 *
 * PURE: no database, no clock. That is what makes the fail-closed default
 * testable — the rule that matters most here is the one that applies when there
 * is nothing to read.
 *
 * THE DEFAULT IS NO. If no consent record exists for a purpose, processing is
 * not permitted. The opposite default — permit unless something says otherwise —
 * is how a mailing list ends up emailing people who never opted in, and the
 * proof of consent is exactly the thing that cannot be produced afterwards.
 *
 * With several rows for one subject and purpose, the LATEST wins. The schema has
 * no unique key on (subject, consent_type), so a re-grant legitimately produces a
 * second row, and the history has to be read newest-first or a withdrawal looks
 * like permission.
 *
 * @see ConsentRecord for why `withdrawnAt` has no column to persist into.
 */
final class ConsentPolicy
{
    /**
     * @param  list<ConsentRecord> $consents every consent held for this subject,
     *                                       any purpose; filtering by purpose is
     *                                       this method's job, not the caller's
     */
    public function canProcess(array $consents, string $purpose): ConsentDecision
    {
        $forPurpose = array_values(array_filter(
            $consents,
            static fn (ConsentRecord $c): bool => $c->type === $purpose
        ));

        if ($forPurpose === []) {
            if ($consents === []) {
                return ConsentDecision::denied(
                    ConsentDecision::NO_RECORD,
                    sprintf('no consent of any kind is recorded; "%s" is not permitted by default.', $purpose)
                );
            }

            $others = implode(', ', array_unique(array_map(
                static fn (ConsentRecord $c): string => $c->type,
                $consents
            )));

            return ConsentDecision::denied(
                ConsentDecision::NOT_FOR_PURPOSE,
                sprintf(
                    'consent exists for [%s] but not for "%s"; consent to one purpose is '
                    . 'not consent to another.',
                    $others,
                    $purpose
                )
            );
        }

        // Newest first: a re-grant after a withdrawal is a later row.
        usort(
            $forPurpose,
            static fn (ConsentRecord $a, ConsentRecord $b): int => $b->grantedAt <=> $a->grantedAt
        );

        $latest = $forPurpose[0];

        return match ($latest->status) {
            ConsentRecord::GRANTED => ConsentDecision::granted(),

            ConsentRecord::WITHDRAWN => ConsentDecision::denied(
                ConsentDecision::WITHDRAWN,
                sprintf(
                    'consent for "%s" was withdrawn at %s.',
                    $purpose,
                    $latest->withdrawnAt?->format(\DATE_ATOM) ?? 'an unrecorded moment'
                )
            ),

            ConsentRecord::EXPIRED => ConsentDecision::denied(
                ConsentDecision::EXPIRED,
                sprintf('consent for "%s" has lapsed and must be given again.', $purpose)
            ),

            default => ConsentDecision::denied(
                ConsentDecision::NO_RECORD,
                sprintf('consent for "%s" is in an unrecognised state; treating it as absent.', $purpose)
            ),
        };
    }

    /**
     * Withdrawing is always allowed while a consent stands — that is the point of
     * consent — but it must produce a moment, or the withdrawal is unprovable.
     */
    public function canWithdraw(ConsentRecord $consent): ConsentDecision
    {
        if (! $consent->isGranted()) {
            return ConsentDecision::denied(
                $consent->status === ConsentRecord::WITHDRAWN
                    ? ConsentDecision::WITHDRAWN
                    : ConsentDecision::EXPIRED,
                sprintf('this consent is already %s.', $consent->status)
            );
        }

        return ConsentDecision::granted();
    }
}
