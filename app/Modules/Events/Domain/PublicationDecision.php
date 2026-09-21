<?php

declare(strict_types=1);

namespace App\Modules\Events\Domain;

/**
 * Whether an event may be published or cancelled, and if not, why.
 *
 * A decision object rather than an exception: the admin UI asks "may I publish
 * this?" before writing, and an exception would force it to catch one to answer.
 *
 * SCHEDULED is allowed and is a report, not a refusal. `published_at` in the future
 * is a legitimate way to say "ready, but not yet"; what must not happen is treating
 * it as visible now, which is why it is named rather than silently collapsed into
 * ALLOWED.
 */
final class PublicationDecision
{
    public const ALLOWED = 'allowed';
    public const NO_CHANGE = 'no_change';
    public const SCHEDULED = 'scheduled';

    public const PUBLISHED_WITHOUT_A_MOMENT = 'published_without_a_moment';
    public const EVENT_DELETED = 'event_deleted';
    public const NO_SESSIONS = 'no_sessions';
    public const HAS_SOLD_UNITS = 'has_sold_units';

    private function __construct(
        public readonly string $verdict,
        public readonly bool $allowed,
        public readonly ?string $reason = null,
    ) {
    }

    public static function allowed(): self
    {
        return new self(self::ALLOWED, true);
    }

    public static function noChange(string $reason): self
    {
        return new self(self::NO_CHANGE, true, $reason);
    }

    public static function scheduled(string $reason): self
    {
        return new self(self::SCHEDULED, true, $reason);
    }

    public static function denied(string $verdict, string $reason): self
    {
        return new self($verdict, false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /** True when there is genuinely something to write. */
    public function requiresWrite(): bool
    {
        return $this->verdict === self::ALLOWED || $this->verdict === self::SCHEDULED;
    }
}
