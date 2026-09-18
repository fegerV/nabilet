<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\InvalidStateTransitionError;
use Nabilet\Core\StateMachine\StateMachine;
use Nabilet\Tests\Support\TestCase;

final class StateMachineTest extends TestCase
{
    private function machine(): StateMachine
    {
        return StateMachine::define(
            name: 'Demo',
            initial: 'a',
            states: ['a', 'b', 'c', 'z'],
            transitions: [
                'a' => ['b', 'c'],
                'b' => ['c'],
                'c' => [],
                'z' => [],
            ],
            terminal: ['c', 'z'],
        );
    }

    public function testExposesItsGraph(): void
    {
        $m = $this->machine();

        $this->assertSame('Demo', $m->name());
        $this->assertSame('a', $m->initial());
        $this->assertSame(['a', 'b', 'c', 'z'], $m->states());
        $this->assertSame(['b', 'c'], $m->allowedFrom('a'));
        $this->assertSame([], $m->allowedFrom('c'));
    }

    public function testCanAndCannot(): void
    {
        $m = $this->machine();

        $this->assertTrue($m->can('a', 'b'));
        $this->assertTrue($m->can('a', 'c'));
        $this->assertFalse($m->can('c', 'a'));
        $this->assertFalse($m->can('a', 'z'));
    }

    public function testIsTerminalAndKnownState(): void
    {
        $m = $this->machine();

        $this->assertTrue($m->isTerminal('c'));
        $this->assertFalse($m->isTerminal('a'));
        $this->assertTrue($m->isKnownState('b'));
        $this->assertFalse($m->isKnownState('nope'));
    }

    public function testTransitionReturnsTargetState(): void
    {
        $this->assertSame('b', $this->machine()->transition('a', 'b'));
    }

    public function testIllegalTransitionThrowsWithDiagnostics(): void
    {
        $m = $this->machine();

        try {
            $m->transition('a', 'z');
            $this->fail('Expected InvalidStateTransitionError.');
        } catch (InvalidStateTransitionError $e) {
            $this->assertSame('Demo', $e->machine);
            $this->assertSame('a', $e->from);
            $this->assertSame('z', $e->to);
            $this->assertSame(['b', 'c'], $e->allowed);
            $this->assertSame(409, $e->status);
            $this->assertSame('INVALID_STATE_TRANSITION', $e->errorCode);
        }
    }

    public function testUnknownSourceStateIsRejected(): void
    {
        $this->assertThrows(
            InvalidStateTransitionError::class,
            fn () => $this->machine()->assert('ghost', 'a')
        );
    }

    public function testUnknownTargetStateIsRejected(): void
    {
        $this->assertThrows(
            InvalidStateTransitionError::class,
            fn () => $this->machine()->assert('a', 'ghost')
        );
    }

    public function testGuardCanVetoATransition(): void
    {
        $m = $this->machine();
        $m->guard('a', 'b', static fn (array $ctx): bool => ($ctx['allowed'] ?? false) === true);

        $this->assertThrows(
            InvalidStateTransitionError::class,
            static fn () => $m->assert('a', 'b', ['allowed' => false])
        );

        // and passes when the guard is satisfied
        $m->assert('a', 'b', ['allowed' => true]);
        $this->assertTrue(true);
    }

    public function testGuardVetoIsReportedAsGuardRejection(): void
    {
        $m = $this->machine();
        $m->guard('a', 'b', static fn (): bool => false);

        try {
            $m->assert('a', 'b');
            $this->fail('Expected rejection.');
        } catch (InvalidStateTransitionError $e) {
            $this->assertSame('guard_rejected', $e->context['reason'] ?? null);
        }
    }

    public function testMultipleGuardsMustAllPass(): void
    {
        $m = $this->machine();
        $m->guard('a', 'b', static fn (): bool => true);
        $m->guard('a', 'b', static fn (): bool => false);

        $this->assertThrows(InvalidStateTransitionError::class, static fn () => $m->assert('a', 'b'));
    }

    public function testReachableFromWalksTheGraph(): void
    {
        $m = $this->machine();

        $this->assertSame(['b', 'c'], $m->reachableFrom('a'));
        $this->assertSame([], $m->reachableFrom('c'));
    }
}
