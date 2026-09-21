<?php

declare(strict_types=1);

namespace App\Modules\HallSchemas\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * One row of `hall_schema_versions`.
 *
 * `hasPayload` stands in for `schema_json`. The JSON itself is never loaded into
 * a domain object: it is a document the editor renders, not a business rule, and
 * dragging megabytes of seat coordinates through a policy class would buy nothing.
 * What the policy needs is only whether there is anything to publish at all.
 *
 * `publishedAt` is nullable because a draft has not been published yet — and it
 * survives archiving, because when a map went live is a fact about it that
 * retiring does not erase.
 */
final class SchemaVersion
{
    public function __construct(
        public readonly int $id,
        public readonly int $hallId,
        public readonly int $version,
        public readonly string $status,
        public readonly bool $hasPayload,
        public readonly ?\DateTimeImmutable $publishedAt = null,
    ) {
        if (! SchemaVersionState::isValid($status)) {
            throw new DomainRuleViolation(
                sprintf('"%s" is not a schema version status.', $status),
                'INVALID_SCHEMA_STATUS'
            );
        }

        // ck_schema_hall_version is (hall_id, version); a zero or negative number
        // would be a row no session could reliably point at.
        if ($version < 1) {
            throw new DomainRuleViolation(
                sprintf('A schema version must be numbered from 1, got %d.', $version),
                'INVALID_VERSION_NUMBER'
            );
        }

        if ($status !== SchemaVersionState::DRAFT && $publishedAt === null) {
            throw new DomainRuleViolation(
                sprintf('Version %d is %s but has no published_at.', $version, $status),
                'MISSING_PUBLISHED_AT'
            );
        }
    }

    public static function draft(int $id, int $hallId, int $version, bool $hasPayload = false): self
    {
        return new self($id, $hallId, $version, SchemaVersionState::DRAFT, $hasPayload);
    }

    public function isDraft(): bool
    {
        return $this->status === SchemaVersionState::DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === SchemaVersionState::PUBLISHED;
    }

    public function isArchived(): bool
    {
        return $this->status === SchemaVersionState::ARCHIVED;
    }

    /** Anything that has left `draft` is frozen — `archived` included. */
    public function isFrozen(): bool
    {
        return SchemaVersionState::isFrozen($this->status);
    }

    /**
     * May the payload be rewritten in place?
     *
     * This is what the trigger will eventually decide, priced in advance: without
     * it the failure surfaces as a constraint violation from inside an UPDATE,
     * after the editor has already told the user their work was saved.
     */
    public function canMutatePayload(): bool
    {
        return ! $this->isFrozen();
    }
}
