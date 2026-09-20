<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\StateMachines;

use Nabilet\Core\StateMachine\StateMachine;

/**
 * Event lifecycle (ТЗ §12).
 *
 * The distinction that matters commercially is `draft` → `published`: only
 * published events are indexable, appear in the catalog and in the sitemap
 * (ТЗ §38 excludes drafts). `archived` is terminal so that SEO URLs of past
 * events stay resolvable via the redirect manager (ТЗ §37) rather than 404ing.
 */
final class EventStateMachine
{
    public const DRAFT = 'draft';
    public const SCHEDULED = 'scheduled';
    public const PUBLISHED = 'published';
    public const COMPLETED = 'completed';
    /**
     * `cancelled`, two L — the same convention as orders, sessions and tickets.
     * events.status has no CHECK constraint to enforce it, which is why this is
     * pinned by convention (and by tests) rather than by the database.
     */
    public const CANCELED = 'cancelled';
    public const ARCHIVED = 'archived';

    public static function make(): StateMachine
    {
        return StateMachine::define(
            name: 'Event',
            initial: self::DRAFT,
            states: [
                self::DRAFT,
                self::SCHEDULED,
                self::PUBLISHED,
                self::COMPLETED,
                self::CANCELED,
                self::ARCHIVED,
            ],
            transitions: [
                self::DRAFT => [self::SCHEDULED, self::PUBLISHED, self::ARCHIVED],
                self::SCHEDULED => [self::PUBLISHED, self::DRAFT, self::CANCELED],
                self::PUBLISHED => [self::COMPLETED, self::CANCELED, self::ARCHIVED, self::SCHEDULED],
                self::COMPLETED => [self::ARCHIVED],
                self::CANCELED => [self::ARCHIVED],
                self::ARCHIVED => [],
            ],
            terminal: [self::ARCHIVED],
        );
    }

    /** @return list<string> states that are publicly visible in the catalog */
    public static function publiclyVisible(): array
    {
        return [self::PUBLISHED, self::COMPLETED];
    }
}
