<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * One row of `events`, plus the two facts the row cannot know.
 *
 * `sessionCount` and `soldUnits` are supplied by the caller because nothing in the
 * event row can answer either: `events` has no session counter and no sales
 * counter, and `UPDATE events SET status = 'cancelled'` was accepted on MySQL 8.4
 * without consulting either (§3.20). Passing them in is what lets the domain ask
 * "has anything been sold?" without reaching for a repository.
 *
 * THE STATUS VOCABULARY IS NOT MINE TO FIX. `events.status` is
 * `VARCHAR(32) DEFAULT 'draft'` with no CHECK, and `'banana'` was accepted. So the
 * status is never validated — only compared, exactly as `carts.status` and
 * `ip_rules.rule_type` are handled. Anything that is not 'published' is not
 * published, which fails closed.
 *
 * `published_at` is NULLABLE, and a row with `status = 'published'` and
 * `published_at IS NULL` was accepted. Such an event is on the site with no answer
 * to "since when?" — which is what a sitemap, a cache header and a support
 * operator all ask first. `publishedWithoutAMoment()` is how the audit finds it.
 */
final class Event
{
    public const PUBLISHED = 'published';

    public function __construct(
        public readonly int|string $id,
        public readonly int $organizationId,
        public readonly string $slug,
        public readonly string $status = 'draft',
        public readonly ?\DateTimeImmutable $publishedAt = null,
        public readonly ?\DateTimeImmutable $deletedAt = null,
        public readonly int $sessionCount = 0,
        public readonly int $soldUnits = 0,
    ) {
        if ($sessionCount < 0 || $soldUnits < 0) {
            throw new DomainRuleViolation(
                'Session and sales counts cannot be negative.',
                'INVALID_EVENT_COUNTS'
            );
        }

        if ($soldUnits > 0 && $sessionCount === 0) {
            throw new DomainRuleViolation(
                sprintf(
                    'Event %s reports %d sold unit(s) but no sessions; units are sold against '
                    . 'sessions, so the caller is passing inconsistent facts.',
                    (string) $id,
                    $soldUnits
                ),
                'INCONSISTENT_EVENT_COUNTS'
            );
        }
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function hasSessions(): bool
    {
        return $this->sessionCount > 0;
    }

    public function hasSoldUnits(): bool
    {
        return $this->soldUnits > 0;
    }

    /** On the site with no record of when it went up. */
    public function publishedWithoutAMoment(): bool
    {
        return $this->isPublished() && $this->publishedAt === null;
    }

    /** Published, but the moment has not arrived — legitimate, and worth reporting. */
    public function isScheduledAt(\DateTimeImmutable $now): bool
    {
        return $this->isPublished() && $this->publishedAt !== null && $this->publishedAt > $now;
    }

    /**
     * Visible to a visitor right now.
     *
     * Published, with a moment, and that moment has passed. A scheduled event is
     * deliberately not visible yet: "published" means the organizer has finished,
     * not that the public may look.
     */
    public function isVisibleAt(\DateTimeImmutable $now): bool
    {
        return $this->isPublished()
            && $this->publishedAt !== null
            && $this->publishedAt <= $now
            && ! $this->isDeleted();
    }
}
