<?php

declare(strict_types=1);

namespace Nabilet\Modules\Venues\Halls\Domain;

/**
 * Schema version policy: rules for activating/deactivating hall schema versions.
 *
 * A schema version can be activated only if it is in 'draft' state.
 * Once activated, it becomes the live layout for the hall.
 * Only one version per hall can be active at a time.
 */
class SchemaVersionPolicy
{
    public const STATE_DRAFT = 'draft';
    public const STATE_ACTIVE = 'active';
    public const STATE_ARCHIVED = 'archived';

    public static function canActivate(string $currentState): bool
    {
        return $currentState === self::STATE_DRAFT;
    }

    public static function canArchive(string $currentState): bool
    {
        return $currentState === self::STATE_ACTIVE || $currentState === self::STATE_DRAFT;
    }
}
