<?php

declare(strict_types=1);

namespace Nabilet\Modules\Security\Domain;

/**
 * The answer to "may this address through?" and, separately, whether the ruleset
 * that answered is one anybody should trust.
 *
 * ABSTAIN exists because the schema cannot express the default. `ip_rules` holds
 * both allow and deny rows in one table and records nowhere what happens when
 * NOTHING matches — so the caller must supply it. Guessing is how an allow-list
 * silently becomes a blocklist, or a blocklist silently becomes no protection.
 */
final class IpRuleDecision
{
    public const ALLOW = 'allow';
    public const DENY = 'deny';
    public const ABSTAIN = 'abstain';
    public const INCOHERENT_RULESET = 'incoherent_ruleset';

    public const OK = 'ok';
    public const NO_TARGET = 'no_target';
    public const BOTH_TARGETS = 'both_targets';
    public const UNKNOWN_TYPE = 'unknown_type';
    public const EXPIRED_BUT_ACTIVE = 'expired_but_active';

    private function __construct(
        public readonly string $verdict,
        public readonly bool $allowed,
        public readonly ?string $reason = null,
    ) {
    }

    public static function allow(string $reason): self
    {
        return new self(self::ALLOW, true, $reason);
    }

    public static function deny(string $reason): self
    {
        return new self(self::DENY, false, $reason);
    }

    /** No rule covered the address; the caller's default applies. */
    public static function abstain(bool $defaultAllow): self
    {
        return new self(self::ABSTAIN, $defaultAllow, 'No rule covers this address; the default applies.');
    }

    public static function incoherent(string $reason): self
    {
        return new self(self::INCOHERENT_RULESET, false, $reason);
    }

    public static function ok(): self
    {
        return new self(self::OK, true);
    }

    public static function defective(string $verdict, string $reason): self
    {
        return new self($verdict, false, $reason);
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isAbstain(): bool
    {
        return $this->verdict === self::ABSTAIN;
    }
}
