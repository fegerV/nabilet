<?php

declare(strict_types=1);

namespace Nabilet\Modules\Payments\Domain;

/**
 * What a webhook is allowed to do — or why it is not allowed to do anything.
 *
 * A result rather than a mutation, for two reasons. The HTTP handler must be able
 * to answer 200 to a duplicate delivery without touching the database, so "this is
 * a replay" has to be a value it can inspect. And the settlement writes to four
 * tables at once (payment, order, tickets, holds), so the decision has to be
 * computed completely before any of it is applied — a half-applied settlement is
 * worse than a refused one.
 *
 * `null` for a status means "leave it alone". That is not the same as "no change":
 * a replay and a refusal both change nothing, but they are logged and answered
 * differently, so they are distinct outcomes.
 */
final class SettlementDecision
{
    private function __construct(
        private readonly bool $applied,
        private readonly bool $replay,
        private readonly ?string $reason,
        private readonly ?string $paymentStatus,
        private readonly ?string $orderStatus,
        private readonly bool $issueTickets,
        private readonly bool $releaseHolds,
    ) {
    }

    /** A duplicate delivery: already in the state this callback asks for. */
    public static function replay(): self
    {
        return new self(false, true, null, null, null, false, false);
    }

    public static function refused(string $reason): self
    {
        return new self(false, false, $reason, null, null, false, false);
    }

    public static function apply(
        ?string $paymentStatus,
        ?string $orderStatus,
        bool $issueTickets = false,
        bool $releaseHolds = false,
    ): self {
        return new self(true, false, null, $paymentStatus, $orderStatus, $issueTickets, $releaseHolds);
    }

    public function isApplied(): bool
    {
        return $this->applied;
    }

    public function isReplay(): bool
    {
        return $this->replay;
    }

    public function isRefused(): bool
    {
        return $this->reason !== null;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function paymentStatus(): ?string
    {
        return $this->paymentStatus;
    }

    public function orderStatus(): ?string
    {
        return $this->orderStatus;
    }

    public function issueTickets(): bool
    {
        return $this->issueTickets;
    }

    public function releaseHolds(): bool
    {
        return $this->releaseHolds;
    }

    /** True when the callback produces no write at all — safe to answer 200. */
    public function isNoOp(): bool
    {
        return ! $this->applied;
    }
}
