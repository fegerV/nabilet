<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * A data subject asking us to do something about their data (ТЗ §72, 152-ФЗ).
 *
 * `privacy_requests.status` and `.type` both have NO CHECK, so — like
 * `consents.status` — the vocabularies below are the application's and an
 * unrecognised value is treated as unusable rather than as valid.
 *
 * THE DEADLINE IS A PARAMETER, NOT A CONSTANT, and that is deliberate. Neither
 * the ТЗ nor the OpenAPI contract states the response period, and I am not
 * willing to hard-code a statutory figure I cannot verify — a wrong number baked
 * into the one place everything trusts is worse than a number the caller must
 * supply. This is the same decision that was made about the service fee in
 * Pricing: where the specification is silent, the value is passed in, not
 * invented.
 *
 * `dueAt` is computed, never stored, because THE SCHEMA HAS NO COLUMN FOR IT.
 * Verified against MySQL 8.4: `privacy_requests` has no column whose name
 * suggests a due date, deadline or response date. A statutory clock that cannot
 * be written down cannot be reported on — see REVIEW §3.15.
 */
final class PrivacyRequest
{
    public const TYPE_ACCESS = 'access';
    public const TYPE_ERASURE = 'erasure';
    public const TYPE_RECTIFICATION = 'rectification';
    public const TYPE_RESTRICTION = 'restriction';
    public const TYPE_PORTABILITY = 'portability';

    public const REQUESTED = 'requested';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED = 'completed';
    public const REJECTED = 'rejected';

    public function __construct(
        public readonly string $publicId,
        public readonly string $type,
        public readonly string $status,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $completedAt = null,
    ) {
        if (! in_array($type, self::types(), true)) {
            throw new DomainRuleViolation(
                sprintf('"%s" is not a privacy request type.', $type),
                'INVALID_PRIVACY_REQUEST_TYPE'
            );
        }

        if (! in_array($status, self::statuses(), true)) {
            throw new DomainRuleViolation(
                sprintf('"%s" is not a privacy request status.', $status),
                'INVALID_PRIVACY_REQUEST_STATUS'
            );
        }

        if (in_array($status, [self::COMPLETED, self::REJECTED], true) && $completedAt === null) {
            throw new DomainRuleViolation(
                sprintf('A %s request must record when it was closed.', $status),
                'MISSING_COMPLETED_AT'
            );
        }

        if ($completedAt !== null && $completedAt < $createdAt) {
            throw new DomainRuleViolation(
                'A request cannot be closed before it was made.',
                'IMPOSSIBLE_REQUEST_TIMELINE'
            );
        }
    }

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::TYPE_ACCESS,
            self::TYPE_ERASURE,
            self::TYPE_RECTIFICATION,
            self::TYPE_RESTRICTION,
            self::TYPE_PORTABILITY,
        ];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::REQUESTED, self::IN_PROGRESS, self::COMPLETED, self::REJECTED];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::REJECTED], true);
    }

    /**
     * @param  int $slaDays the response period; see the class docblock for why it
     *                      is supplied rather than assumed
     */
    public function dueAt(int $slaDays): \DateTimeImmutable
    {
        return $this->createdAt->modify(sprintf('+%d days', $slaDays));
    }

    public function isOverdue(\DateTimeImmutable $now, int $slaDays): bool
    {
        if ($this->isTerminal()) {
            return false; // a closed request is late or on time, never "overdue"
        }

        return $now > $this->dueAt($slaDays);
    }
}
