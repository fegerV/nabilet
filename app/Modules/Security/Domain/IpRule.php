<?php

declare(strict_types=1);

namespace Nabilet\Modules\Security\Domain;

/**
 * One row of `ip_rules`.
 *
 * The row can carry an address, a CIDR, both, or neither — and MySQL 8.4 accepted
 * all four (see `tools/repro-api-key-rules.sql`). That is the whole problem:
 *
 *   neither   a `deny` rule with no target. Read one way it blocks nothing; read
 *             the other it blocks everyone. The row does not say which.
 *   both      a rule that names 10.0.0.1 AND 192.168.0.0/24. Whatever the code
 *             decides — match either, or match both — it is inventing an answer.
 *
 * A rule that cannot be read unambiguously must not be evaluated at all, and
 * `isReadable()` is the gate.
 *
 * `rule_type` is `VARCHAR(16)` with no CHECK, and `'banana'` was accepted. As with
 * `carts.status`, the vocabulary is a proposal, so the type is never validated —
 * only compared, and anything that is neither allow nor deny cannot be evaluated.
 */
final class IpRule
{
    public const ALLOW = 'allow';
    public const DENY = 'deny';

    public function __construct(
        public readonly int|string $id,
        public readonly string $ruleType,
        public readonly bool $active = true,
        public readonly ?string $ipAddress = null,
        public readonly ?string $cidr = null,
        public readonly ?\DateTimeImmutable $expiresAt = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function hasTarget(): bool
    {
        return $this->ipAddress !== null || $this->cidr !== null;
    }

    public function hasBothTargets(): bool
    {
        return $this->ipAddress !== null && $this->cidr !== null;
    }

    /**
     * Can this rule be evaluated at all?
     *
     * Exactly one target, a type that is either allow or deny, and a known type.
     * Everything else is reported rather than guessed at.
     */
    public function isReadable(): bool
    {
        return $this->hasTarget()
            && ! $this->hasBothTargets()
            && ($this->ruleType === self::ALLOW || $this->ruleType === self::DENY);
    }

    public function isAllow(): bool
    {
        return $this->ruleType === self::ALLOW;
    }

    public function isDeny(): bool
    {
        return $this->ruleType === self::DENY;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }

    /** Expired, but nobody flipped `active` to 0 — accepted by MySQL. */
    public function isExpiredButActive(\DateTimeImmutable $now): bool
    {
        return $this->active && $this->isExpiredAt($now);
    }

    public function isInForceAt(\DateTimeImmutable $now): bool
    {
        return $this->active && ! $this->isExpiredAt($now);
    }

    /**
     * Does this rule cover the address?
     *
     * False for an unreadable rule: it has nothing coherent to compare against.
     * Deliberately no exception — a malformed rule is data, not a crash.
     */
    public function matches(string $address): bool
    {
        if (! $this->isReadable()) {
            return false;
        }

        if ($this->cidr !== null) {
            return $this->inCidr($address, $this->cidr);
        }

        $target = @inet_pton(trim((string) $this->ipAddress));
        $probe = @inet_pton(trim($address));

        return $target !== false && $probe !== false && $target === $probe;
    }

    private function inCidr(string $address, string $cidr): bool
    {
        $cidr = trim($cidr);
        $slash = strpos($cidr, '/');

        if ($slash === false) {
            return false;
        }

        $network = @inet_pton(substr($cidr, 0, $slash));
        $probe = @inet_pton(trim($address));
        $bits = filter_var(substr($cidr, $slash + 1), \FILTER_VALIDATE_INT);

        if ($network === false || $probe === false || $bits === false) {
            return false;
        }

        if (strlen($network) !== strlen($probe) || $bits < 0 || $bits > strlen($network) * 8) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($network, 0, $bytes) !== substr($probe, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0 || $bytes === strlen($network)) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return (ord($network[$bytes]) & ord($mask)) === (ord($probe[$bytes]) & ord($mask));
    }
}
