<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Domain;

/**
 * Publishing and cancelling an event (ТЗ §10, §13).
 *
 * Gaps reproduced against MySQL 8.4 first, in `tools/repro-event-publication.sql`;
 * collected in REVIEW §3.20.
 *
 * THE ONE RULE HERE A PRODUCT OWNER MIGHT RELAX
 *   Publishing an event with no sessions is refused. An event with no sessions has
 *   no date, no price and no buy button, so publishing it puts a dead page on the
 *   site and into the sitemap. If "announce before the dates are known" is wanted,
 *   that is a product decision and this is the single place to change it — but then
 *   the page must say so, and today nothing in the schema distinguishes the two.
 *
 * WHAT IS DELIBERATELY NOT DECIDED
 *   `published_at` in the future is allowed and reported as SCHEDULED. Scheduled
 *   publication is a real feature and forbidding it would be inventing a limit;
 *   what matters is that it is not treated as visible, which `isVisibleAt()` does.
 */
final class EventPublicationPolicy
{
    /**
     * May this event be published?
     *
     * A republish of an event that is already up WITH a moment is NO_CHANGE, so
     * saving the form twice does not write an UPDATE that bumps `updated_at` and
     * nothing else. An event that is published but has NO moment is work: the
     * caller writes the missing `published_at`, which repairs the row the schema
     * should never have allowed.
     */
    public function publishDecision(Event $event): PublicationDecision
    {
        if ($event->isDeleted()) {
            return PublicationDecision::denied(
                PublicationDecision::EVENT_DELETED,
                sprintf('Event %s is deleted; a deleted event cannot be published.', (string) $event->id)
            );
        }

        if (! $event->hasSessions()) {
            return PublicationDecision::denied(
                PublicationDecision::NO_SESSIONS,
                sprintf(
                    'Event %s has no sessions: publishing it would put up a page with no date, '
                    . 'no price and nothing to buy.',
                    (string) $event->id
                )
            );
        }

        if ($event->isPublished() && $event->publishedAt !== null) {
            return PublicationDecision::noChange(sprintf(
                'Event %s is already published at %s.',
                (string) $event->id,
                $event->publishedAt->format('c')
            ));
        }

        return PublicationDecision::allowed();
    }

    /**
     * May this event be cancelled?
     *
     * Refused while anything has been sold. Not because cancelling is impossible,
     * but because the row has no column that could object — the UPDATE was accepted
     * on MySQL 8.4 with no condition at all — so the objection has to live here, and
     * it has to happen before the refunds are settled, not after.
     */
    public function cancelDecision(Event $event): PublicationDecision
    {
        if ($event->isDeleted()) {
            return PublicationDecision::noChange(sprintf(
                'Event %s is already deleted at %s.',
                (string) $event->id,
                $event->deletedAt?->format('c') ?? '—'
            ));
        }

        if ($event->hasSoldUnits()) {
            return PublicationDecision::denied(
                PublicationDecision::HAS_SOLD_UNITS,
                sprintf(
                    'Event %s has %d sold unit(s). Nothing in the schema consults sales before '
                    . 'a cancellation, so the refunds must be settled first — otherwise tickets '
                    . 'are voided before anybody has been paid back.',
                    (string) $event->id,
                    $event->soldUnits
                )
            );
        }

        return PublicationDecision::allowed();
    }

    /**
     * Audit a row that already exists.
     *
     * The missing moment is checked first: it is the defect the schema allowed, and
     * "visible" would be a misleadingly healthy answer for it.
     */
    public function auditDecision(Event $event, \DateTimeImmutable $now): PublicationDecision
    {
        if ($event->publishedWithoutAMoment()) {
            return PublicationDecision::denied(
                PublicationDecision::PUBLISHED_WITHOUT_A_MOMENT,
                sprintf(
                    'Event %s is published with published_at = NULL: it is on the site with no '
                    . 'answer to "since when?", which is what a sitemap, a cache header and a '
                    . 'support operator ask first.',
                    (string) $event->id
                )
            );
        }

        if ($event->isScheduledAt($now)) {
            return PublicationDecision::scheduled(sprintf(
                'Event %s is published but scheduled for %s; it is not visible yet.',
                (string) $event->id,
                $event->publishedAt?->format('c') ?? '—'
            ));
        }

        if ($event->isPublished() && ! $event->hasSessions()) {
            return PublicationDecision::denied(
                PublicationDecision::NO_SESSIONS,
                sprintf('Event %s is published with no sessions.', (string) $event->id)
            );
        }

        return PublicationDecision::allowed();
    }
}
