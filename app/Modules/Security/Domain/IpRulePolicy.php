<?php

declare(strict_types=1);

namespace App\Modules\Security\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * Whether an address may pass, and whether the rules that decide are readable
 * (ТЗ §79).
 *
 * Gaps reproduced against MySQL 8.4 first, in `tools/repro-api-key-rules.sql`;
 * collected in REVIEW §3.19.
 *
 * THE ONE DECISION HERE THAT IS A TRADE-OFF, STATED AS ONE
 *   An unreadable rule (no target, two targets, or a type that is neither allow
 *   nor deny) makes the whole ruleset refuse to evaluate, and the request is
 *   DENIED. That fails closed, which is the choice made everywhere else in this
 *   codebase — consent with no record, an unknown cart status, a session with no
 *   expiry. The cost is real and worth saying: one malformed row blocks traffic
 *   until it is fixed, so the audit matters more than usual here. The alternative
 *   — ignoring the unreadable row — silently disables a blocklist, which is the
 *   failure nobody notices.
 *
 * WHY DENY BEATS ALLOW
 *   Not by specificity, but absolutely. If an allow could outrank a deny, a single
 *   broad allow row would quietly retire every block in the table.
 *
 * WHY THE DEFAULT IS A PARAMETER
 *   `ip_rules` mixes allow and deny rows and records nowhere what happens when
 *   nothing matches. If that default lives in `settings`, it is a separate row that
 *   nothing ties to this table. So the caller passes it, and ABSTAIN reports that
 *   no rule covered the address.
 */
final class IpRulePolicy
{
    /**
     * @param list<IpRule> $rules
     */
    public function evaluate(string $address, array $rules, \DateTimeImmutable $now, bool $defaultAllow): IpRuleDecision
    {
        foreach ($rules as $rule) {
            if (! $rule instanceof IpRule) {
                throw new DomainRuleViolation('IpRulePolicy accepts only IpRule instances.', 'INVALID_IP_RULE');
            }
        }

        // Only rules actually in force take part: not inactive, not expired.
        $live = array_values(array_filter(
            $rules,
            static fn (IpRule $rule): bool => $rule->isInForceAt($now)
        ));

        $unreadable = array_values(array_filter(
            $live,
            static fn (IpRule $rule): bool => ! $rule->isReadable()
        ));

        if ($unreadable !== []) {
            $ids = implode(', ', array_map(static fn (IpRule $r): string => (string) $r->id, $unreadable));

            return IpRuleDecision::incoherent(sprintf(
                'Rule(s) %s cannot be read (no target, two targets, or a type that is neither '
                . 'allow nor deny), so the ruleset is not evaluable. Fix the row rather than '
                . 'letting it silently disable the others.',
                $ids
            ));
        }

        $matching = array_values(array_filter(
            $live,
            static fn (IpRule $rule): bool => $rule->matches($address)
        ));

        foreach ($matching as $rule) {
            if ($rule->isDeny()) {
                return IpRuleDecision::deny(sprintf('Rule %s denies this address.', (string) $rule->id));
            }
        }

        foreach ($matching as $rule) {
            if ($rule->isAllow()) {
                return IpRuleDecision::allow(sprintf('Rule %s allows this address.', (string) $rule->id));
            }
        }

        return IpRuleDecision::abstain($defaultAllow);
    }

    /**
     * Audit one rule — the only way to find the rows MySQL already accepted.
     */
    public function auditDecision(IpRule $rule, \DateTimeImmutable $now): IpRuleDecision
    {
        if (! $rule->hasTarget()) {
            return IpRuleDecision::defective(
                IpRuleDecision::NO_TARGET,
                sprintf('Rule %s names neither an address nor a CIDR.', (string) $rule->id)
            );
        }

        if ($rule->hasBothTargets()) {
            return IpRuleDecision::defective(
                IpRuleDecision::BOTH_TARGETS,
                sprintf('Rule %s names both an address and a CIDR.', (string) $rule->id)
            );
        }

        if ($rule->ruleType !== IpRule::ALLOW && $rule->ruleType !== IpRule::DENY) {
            return IpRuleDecision::defective(
                IpRuleDecision::UNKNOWN_TYPE,
                sprintf('Rule %s has rule_type "%s".', (string) $rule->id, $rule->ruleType)
            );
        }

        // Accepted by MySQL: expires_at a year in the past with active = 1.
        if ($rule->isExpiredButActive($now)) {
            return IpRuleDecision::defective(
                IpRuleDecision::EXPIRED_BUT_ACTIVE,
                sprintf(
                    'Rule %s expired at %s but is still active.',
                    (string) $rule->id,
                    $rule->expiresAt?->format('c') ?? '—'
                )
            );
        }

        return IpRuleDecision::ok();
    }
}
