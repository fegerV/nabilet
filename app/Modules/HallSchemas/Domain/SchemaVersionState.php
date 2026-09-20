<?php

declare(strict_types=1);

namespace Nabilet\Modules\HallSchemas\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * The lifecycle of one hall schema version (ТЗ §20, §46, §98).
 *
 * Unlike carts, this vocabulary IS backed by the schema: `ck_schema_status`
 * allows exactly `draft`, `published`, `archived`.
 *
 * WHY PUBLISHED VERSIONS ARE IMMUTABLE AT ALL
 *   A session sells seats against a specific schema version. If that version could
 *   be edited after the fact, a customer's seat 12 row 4 could move, disappear or
 *   become a standing place while their ticket still says 12/4 — and the check-in
 *   device would be validating against a map that no longer exists. Immutability
 *   is what makes a sold seat a fact rather than a promise.
 *
 * The trigger `trg_schema_version_immutable` enforces this in the database.
 * FROZEN_FIELDS below is the same list, kept in one readable place so the domain
 * can refuse an edit with a clear reason instead of letting MySQL raise a
 * constraint violation from inside an UPDATE.
 *
 * `published → archived` remains legal: retiring a map is a status change, not a
 * rewrite. Editing is done by duplicating to a NEW version, never in place.
 */
final class SchemaVersionState
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const ARCHIVED = 'archived';

    /**
     * Columns the trigger refuses to change once a version has left `draft`.
     * Mirrors trg_schema_version_immutable exactly — if one changes, change both.
     *
     * @return list<string>
     */
    public const FROZEN_FIELDS = [
        'schema_json',
        'version',
        'hall_id',
        'width',
        'height',
        'background_url',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [self::DRAFT, self::PUBLISHED, self::ARCHIVED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    public static function isFrozen(string $status): bool
    {
        return $status !== self::DRAFT;
    }

    /** @return list<string> */
    public static function transitionsFrom(string $status): array
    {
        return match ($status) {
            self::DRAFT => [self::PUBLISHED],
            self::PUBLISHED => [self::ARCHIVED],
            self::ARCHIVED => [],
            default => throw new DomainRuleViolation(
                sprintf('Unknown schema version status "%s".', $status),
                'INVALID_SCHEMA_STATUS'
            ),
        };
    }

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::transitionsFrom($from), true);
    }
}
