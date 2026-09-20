<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * One consent, for one purpose, by one subject (ТЗ §72, 152-ФЗ).
 *
 * `consents.status` has NO CHECK constraint — it is one of the 19 free-form
 * status columns in REVIEW-spec-bundle.md §3.11. So the vocabulary below is the
 * application's, and it is applied FAIL-CLOSED: only the literal `granted`
 * authorises processing. An unrecognised status is not consent. That direction
 * matters — if a future release writes `opted_in`, this class refuses to treat
 * it as permission rather than guessing.
 *
 * EXACTLY ONE SUBJECT. The schema allows `user_id` and `anonymous_id` to be NULL
 * simultaneously, and MySQL accepts it (verified). A consent row belonging to
 * nobody cannot be produced in response to a subject access request, and cannot
 * be withdrawn by anyone — it is noise that looks like proof. Both set at once
 * is no better: it is one person under two identities, and whichever one asks
 * for their data gets half an answer.
 *
 * `withdrawnAt` HAS NO COLUMN TO LIVE IN. `consents` carries `created_at` and
 * nothing else; there is no `updated_at` and no `withdrawn_at`. The database
 * therefore cannot record the single most important moment in a consent's life —
 * the moment it stopped. That is the same defect class as `tickets.revoked_at`,
 * and it is handled the same way: the domain carries the fact, the schema gap is
 * documented (REVIEW §3.15), and persisting it needs a column.
 */
final class ConsentRecord
{
    public const GRANTED = 'granted';
    public const WITHDRAWN = 'withdrawn';
    public const EXPIRED = 'expired';

    public function __construct(
        public readonly string $type,
        public readonly string $status,
        public readonly \DateTimeImmutable $grantedAt,
        public readonly ?int $userId = null,
        public readonly ?string $anonymousId = null,
        public readonly ?string $policyVersion = null,
        public readonly ?\DateTimeImmutable $withdrawnAt = null,
    ) {
        if ($type === '') {
            throw new DomainRuleViolation(
                'A consent must name its purpose; "consent" without one is not consent.',
                'INVALID_CONSENT'
            );
        }

        if (! in_array($status, [self::GRANTED, self::WITHDRAWN, self::EXPIRED], true)) {
            throw new DomainRuleViolation(
                sprintf('"%s" is not a consent status.', $status),
                'INVALID_CONSENT_STATUS'
            );
        }

        // Exactly one: XOR.
        if (($userId === null) === ($anonymousId === null)) {
            throw new DomainRuleViolation(
                'A consent must be attached to exactly one subject — a user or an '
                . 'anonymous id, never neither and never both.',
                'INVALID_CONSENT_SUBJECT'
            );
        }

        if ($status === self::WITHDRAWN && $withdrawnAt === null) {
            throw new DomainRuleViolation(
                'A withdrawn consent must record when. "We cannot say when they '
                . 'withdrew" is not an answer a regulator accepts.',
                'MISSING_WITHDRAWN_AT'
            );
        }

        if ($withdrawnAt !== null && $withdrawnAt < $grantedAt) {
            throw new DomainRuleViolation(
                'A consent cannot be withdrawn before it was granted.',
                'IMPOSSIBLE_CONSENT_TIMELINE'
            );
        }
    }

    public static function grant(
        string $type,
        \DateTimeImmutable $at,
        ?int $userId = null,
        ?string $anonymousId = null,
        ?string $policyVersion = null,
    ): self {
        return new self($type, self::GRANTED, $at, $userId, $anonymousId, $policyVersion);
    }

    public function isGranted(): bool
    {
        return $this->status === self::GRANTED;
    }

    /** Consent for one purpose is not consent for another. */
    public function covers(string $type): bool
    {
        return $this->type === $type && $this->isGranted();
    }

    /**
     * Withdrawal is terminal for THIS row: a later grant is a new row, which the
     * schema permits (no unique key on subject + type) and which keeps the
     * history intact instead of overwriting it.
     */
    public function withdraw(\DateTimeImmutable $at): self
    {
        if (! $this->isGranted()) {
            throw new DomainRuleViolation(
                sprintf('This consent is %s; it cannot be withdrawn again.', $this->status),
                'CONSENT_NOT_WITHDRAWABLE'
            );
        }

        return new self(
            type: $this->type,
            status: self::WITHDRAWN,
            grantedAt: $this->grantedAt,
            userId: $this->userId,
            anonymousId: $this->anonymousId,
            policyVersion: $this->policyVersion,
            withdrawnAt: $at,
        );
    }
}
