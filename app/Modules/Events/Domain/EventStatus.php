<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain;

/**
 * Event status constants (ТЗ §12).
 *
 * Centralized definition of event statuses to eliminate magic strings
 * throughout the codebase. These statuses correspond to the EventStateMachine
 * and are used for validation, display, and business logic.
 */
final class EventStatus
{
    /**
     * Initial state: event is being created/edited, not visible publicly.
     */
    public const DRAFT = 'draft';

    /**
     * Event is prepared but scheduled for future publication.
     */
    public const SCHEDULED = 'scheduled';

    /**
     * Event is publicly visible and indexable.
     */
    public const PUBLISHED = 'published';

    /**
     * Event has completed (past end date).
     */
    public const COMPLETED = 'completed';

    /**
     * Event was cancelled by organizer.
     */
    public const CANCELLED = 'cancelled';

    /**
     * Terminal state: event is archived, no longer actively managed.
     */
    public const ARCHIVED = 'archived';

    /**
     * All valid status values.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::DRAFT,
            self::SCHEDULED,
            self::PUBLISHED,
            self::COMPLETED,
            self::CANCELLED,
            self::ARCHIVED,
        ];
    }

    /**
     * Statuses that are publicly visible in the catalog.
     *
     * @return array<int, string>
     */
    public static function publiclyVisible(): array
    {
        return [self::PUBLISHED, self::COMPLETED];
    }

    /**
     * Terminal statuses that cannot transition further (except to archived).
     *
     * @return array<int, string>
     */
    public static function terminal(): array
    {
        return [self::ARCHIVED];
    }

    /**
     * Check if a status value is valid.
     */
    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * Check if status is publicly visible.
     */
    public static function isPubliclyVisible(string $status): bool
    {
        return in_array($status, self::publiclyVisible(), true);
    }

    /**
     * Prevent instantiation.
     */
    private function __construct()
    {
    }
}
