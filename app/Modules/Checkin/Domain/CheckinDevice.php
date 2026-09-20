<?php

declare(strict_types=1);

namespace Nabilet\Modules\Checkin\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * A device authorised to run the Android Checker (ТЗ §31/§33).
 *
 * `checkin_devices.status` has NO CHECK constraint — it is one of the 19 free-form
 * status columns catalogued in REVIEW-spec-bundle.md §3.11. So the vocabulary
 * below is the application's, not the database's, and it is applied
 * FAIL-CLOSED: only the literal 'active' may receive a bundle. Any other value —
 * including one this class has never heard of — is treated as not authorised.
 *
 * That direction matters. If a future release introduces 'blocked' and this class
 * still only knows 'active', the device is refused, which is the safe outcome.
 * The reverse (anything except 'revoked' is allowed) would hand a bundle to a
 * device whose status nobody recognises.
 */
final class CheckinDevice
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_REVOKED = 'revoked';

    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly string $status = self::STATUS_ACTIVE,
    ) {
        if ($id <= 0) {
            throw new DomainRuleViolation(
                sprintf('A check-in device needs a positive id, got %d.', $id),
                'INVALID_DEVICE'
            );
        }

        if ($publicId === '') {
            throw new DomainRuleViolation(
                'A check-in device needs its public id: it is part of the bundle hash.',
                'INVALID_DEVICE'
            );
        }
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
