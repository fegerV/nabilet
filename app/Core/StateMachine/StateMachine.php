<?php

declare(strict_types=1);

namespace Nabilet\Core\StateMachine;

use Nabilet\Core\Errors\InvalidStateTransitionError;

/**
 * Explicit, declarative state machine.
 *
 * Statuses such as `Order.status`, `Payment.status` and `Ticket.status` are the
 * backbone of the domain. Encoding them as free-form strings with scattered
 * `if ($order->status === 'paid')` checks is how a ticketing system ends up with
 * a refunded order that also has a `paid_at` timestamp and an active ticket.
 *
 * Instead every legal transition is declared once, in one place, and enforced.
 *
 * Design notes:
 *  - Transitions are declared `from => [to, ...]`, so the legal graph is readable.
 *  - Optional guards let a transition depend on data ("only if fully refunded").
 *  - `terminal` states are declared explicitly so the UI can hide action buttons
 *    and jobs can skip work without re-deriving the rules.
 *  - Framework-agnostic: no Laravel, no Eloquent, fully unit-testable.
 *
 * @see docs/STATE-MACHINES.md
 */
final class StateMachine
{
    /** @var array<string, list<string>> */
    private array $transitions = [];

    /** @var array<string, list<callable(array<string,mixed>): bool>> */
    private array $guards = [];

    /** @var list<string> */
    private array $terminal = [];

    private function __construct(
        private readonly string $name,
        private readonly string $initial,
        /** @var list<string> */
        private readonly array $states,
    ) {
    }

    /**
     * @param  list<string>                 $states
     * @param  array<string, list<string>>  $transitions
     * @param  list<string>                 $terminal
     */
    public static function define(
        string $name,
        string $initial,
        array $states,
        array $transitions,
        array $terminal = [],
    ): self {
        $machine = new self($name, $initial, $states);
        $machine->transitions = $transitions;
        $machine->terminal = $terminal;

        return $machine;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function initial(): string
    {
        return $this->initial;
    }

    /** @return list<string> */
    public function states(): array
    {
        return $this->states;
    }

    /** @return list<string> */
    public function allowedFrom(string $state): array
    {
        return $this->transitions[$state] ?? [];
    }

    public function can(string $from, string $to): bool
    {
        return in_array($to, $this->transitions[$from] ?? [], true);
    }

    public function isTerminal(string $state): bool
    {
        return in_array($state, $this->terminal, true);
    }

    public function isKnownState(string $state): bool
    {
        return in_array($state, $this->states, true);
    }

    /**
     * Register a guard for one transition. The guard receives the transition
     * context and returns false to veto.
     */
    public function guard(string $from, string $to, callable $guard): self
    {
        $this->guards[$from . '->' . $to][] = $guard;

        return $this;
    }

    /**
     * Assert that a transition is legal. Throws instead of returning false so
     * that an illegal transition can never be ignored by a careless caller.
     *
     * @param array<string, mixed> $context
     */
    public function assert(string $from, string $to, array $context = []): void
    {
        if (! $this->isKnownState($from)) {
            throw new InvalidStateTransitionError($this->name, $from, $to, [], ['reason' => 'unknown_source_state'] + $context);
        }

        if (! $this->isKnownState($to)) {
            throw new InvalidStateTransitionError($this->name, $from, $to, $this->allowedFrom($from), ['reason' => 'unknown_target_state'] + $context);
        }

        if (! $this->can($from, $to)) {
            throw new InvalidStateTransitionError($this->name, $from, $to, $this->allowedFrom($from), $context);
        }

        foreach ($this->guards[$from . '->' . $to] ?? [] as $guard) {
            if ($guard($context) !== true) {
                throw new InvalidStateTransitionError(
                    $this->name,
                    $from,
                    $to,
                    $this->allowedFrom($from),
                    ['reason' => 'guard_rejected'] + $context
                );
            }
        }
    }

    /**
     * Perform a transition, returning the new state.
     *
     * @param  array<string, mixed>  $context
     */
    public function transition(string $from, string $to, array $context = []): string
    {
        $this->assert($from, $to, $context);

        return $to;
    }

    /**
     * Transitions that would be rejected purely by graph shape, ignoring guards.
     *
     * @return list<string>
     */
    public function reachableFrom(string $state): array
    {
        $seen = [];
        $queue = $this->allowedFrom($state);

        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($this->allowedFrom($current) as $next) {
                $queue[] = $next;
            }
        }

        return array_keys($seen);
    }
}
