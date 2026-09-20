<?php

declare(strict_types=1);

namespace Nabilet\Modules\Privacy\Domain;

/**
 * Lifecycle and consequences of a privacy request (ТЗ §72, 152-ФЗ).
 *
 * PURE: no database, no clock. The deadline is supplied by the caller because
 * neither the ТЗ nor the contract fixes it — see PrivacyRequest.
 */
final class PrivacyRequestPolicy
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        PrivacyRequest::REQUESTED => [PrivacyRequest::IN_PROGRESS, PrivacyRequest::COMPLETED, PrivacyRequest::REJECTED],
        PrivacyRequest::IN_PROGRESS => [PrivacyRequest::COMPLETED, PrivacyRequest::REJECTED],
        PrivacyRequest::COMPLETED => [],
        PrivacyRequest::REJECTED => [],
    ];

    public function canTransition(PrivacyRequest $request, string $to): PrivacyDecision
    {
        if (! in_array($to, PrivacyRequest::statuses(), true)) {
            return PrivacyDecision::denied(
                PrivacyDecision::BACKWARDS,
                sprintf('"%s" is not a privacy request status.', $to)
            );
        }

        if ($to === $request->status) {
            return PrivacyDecision::noChange(
                sprintf('the request is already %s; nothing to write.', $to)
            );
        }

        if ($request->isTerminal()) {
            return PrivacyDecision::denied(
                PrivacyDecision::TERMINAL,
                sprintf(
                    'this request is %s. Reopening it would restart a statutory clock '
                    . 'without saying so.',
                    $request->status
                )
            );
        }

        if (! in_array($to, self::TRANSITIONS[$request->status], true)) {
            return PrivacyDecision::denied(
                PrivacyDecision::BACKWARDS,
                sprintf('a %s request cannot move to %s.', $request->status, $to)
            );
        }

        return PrivacyDecision::allowed();
    }

    /**
     * What erasing this person actually involves.
     *
     * @param  int $ordersWithFinancialRecords orders that must be kept for accounting
     */
    public function planErasure(PrivacyRequest $request, int $ordersWithFinancialRecords): ErasurePlan
    {
        if ($request->type !== PrivacyRequest::TYPE_ERASURE) {
            throw new \Nabilet\Core\Errors\DomainRuleViolation(
                sprintf('Request %s is of type "%s"; only erasure has an erasure plan.', $request->publicId, $request->type),
                'NOT_AN_ERASURE_REQUEST'
            );
        }

        if ($ordersWithFinancialRecords < 0) {
            throw new \Nabilet\Core\Errors\DomainRuleViolation(
                sprintf('Cannot anonymize %d orders.', $ordersWithFinancialRecords),
                'INVALID_ERASURE_SCOPE'
            );
        }

        return ErasurePlan::forSubject($ordersWithFinancialRecords);
    }
}
