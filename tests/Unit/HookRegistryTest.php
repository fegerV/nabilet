<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Hooks\HookRegistry;
use Nabilet\Tests\Support\TestCase;

/**
 * The hook bus is the public extension contract. If ordering, argument passing or
 * isolation is wrong, third-party plugins break in ways the core team cannot see.
 */
final class HookRegistryTest extends TestCase
{
    public function testActionsRunInPriorityOrderThenRegistrationOrder(): void
    {
        $hooks = new HookRegistry();
        $calls = [];

        $hooks->addAction('order.paid', function () use (&$calls): void {
            $calls[] = 'default-first';
        }, 10);
        $hooks->addAction('order.paid', function () use (&$calls): void {
            $calls[] = 'early';
        }, 5);
        $hooks->addAction('order.paid', function () use (&$calls): void {
            $calls[] = 'default-second';
        }, 10);
        $hooks->addAction('order.paid', function () use (&$calls): void {
            $calls[] = 'late';
        }, 20);

        $hooks->doAction('order.paid');

        $this->assertSame(['early', 'default-first', 'default-second', 'late'], $calls);
    }

    public function testActionReceivesOnlyAcceptedArgumentCount(): void
    {
        $hooks = new HookRegistry();
        $received = null;

        $hooks->addAction('ticket.issued', function ($ticket) use (&$received): void {
            $received = func_get_args();
        }, 10, 1);

        $hooks->doAction('ticket.issued', 'T-1', 'extra', 'more');

        $this->assertSame(['T-1'], $received);
    }

    public function testActionCanAcceptMultipleArguments(): void
    {
        $hooks = new HookRegistry();
        $received = null;

        $hooks->addAction('payment.completed', function (...$args) use (&$received): void {
            $received = $args;
        }, 10, 3);

        $hooks->doAction('payment.completed', 'P-1', 5300, 'RUB', 'ignored');

        $this->assertSame(['P-1', 5300, 'RUB'], $received);
    }

    public function testFiltersChainValueThroughEveryCallback(): void
    {
        $hooks = new HookRegistry();

        $hooks->addFilter('ticket.price', fn (int $price): int => $price + 300, 20);
        $hooks->addFilter('ticket.price', fn (int $price): int => $price * 2, 10);

        // priority 10 runs first: 5000*2 = 10000, then +300
        $this->assertSame(10300, $hooks->applyFilters('ticket.price', 5000));
    }

    public function testFilterReceivesExtraContextArguments(): void
    {
        $hooks = new HookRegistry();
        $captured = [];

        $hooks->addFilter('event.meta', function (array $meta, $event, $locale) use (&$captured): array {
            $captured = [$event, $locale];
            $meta['localized'] = true;

            return $meta;
        }, 10, 3);

        $result = $hooks->applyFilters('event.meta', ['title' => 'Hamlet'], 'EV-1', 'ru');

        $this->assertSame(['EV-1', 'ru'], $captured);
        $this->assertTrue($result['localized']);
    }

    public function testAFilterWithNoListenersReturnsValueUnchanged(): void
    {
        $hooks = new HookRegistry();

        $this->assertSame(5000, $hooks->applyFilters('ticket.price', 5000));
    }

    public function testRemoveActionStopsInvocation(): void
    {
        $hooks = new HookRegistry();
        $calls = 0;

        $listener = function () use (&$calls): void {
            $calls++;
        };

        $hooks->addAction('order.created', $listener);
        $hooks->doAction('order.created');
        $this->assertSame(1, $calls);

        $this->assertTrue($hooks->removeAction('order.created', $listener));
        $hooks->doAction('order.created');
        $this->assertSame(1, $calls);
    }

    public function testRemoveActionReturnsFalseForUnknownListener(): void
    {
        $hooks = new HookRegistry();

        $this->assertFalse($hooks->removeAction('nothing.here', static fn () => null));
    }

    /**
     * A third-party plugin throwing inside a hook must not abort a checkout.
     * This is the whole point of doActionSafely().
     */
    public function testSafeActionIsolatesThrowingListeners(): void
    {
        $hooks = new HookRegistry();
        $errors = [];
        $ranAfterFailure = false;

        $hooks->addAction('order.paid', function (): void {
            throw new \RuntimeException('plugin is broken');
        }, 5);

        $hooks->addAction('order.paid', function () use (&$ranAfterFailure): void {
            $ranAfterFailure = true;
        }, 10);

        $hooks->doActionSafely('order.paid', function (string $hook, \Throwable $e) use (&$errors): void {
            $errors[] = [$hook, $e->getMessage()];
        }, 'O-1');

        $this->assertTrue($ranAfterFailure);
        $this->assertCount(1, $errors);
        $this->assertSame('order.paid', $errors[0][0]);
        $this->assertSame('plugin is broken', $errors[0][1]);
    }

    public function testDidActionCountsFirings(): void
    {
        $hooks = new HookRegistry();

        $this->assertSame(0, $hooks->didAction('order.paid'));
        $hooks->doAction('order.paid');
        $hooks->doAction('order.paid');
        $this->assertSame(2, $hooks->didAction('order.paid'));
    }

    public function testInspectionReportsRegisteredHooks(): void
    {
        $hooks = new HookRegistry();
        $hooks->addAction('order.paid', static fn () => null);
        $hooks->addAction('ticket.issued', static fn () => null);
        $hooks->addFilter('ticket.price', static fn ($p) => $p);

        $this->assertSame(['order.paid', 'ticket.issued'], $hooks->registeredActions());
        $this->assertSame(['ticket.price'], $hooks->registeredFilters());
        $this->assertTrue($hooks->hasAction('order.paid'));
        $this->assertFalse($hooks->hasAction('nope'));
        $this->assertTrue($hooks->hasFilter('ticket.price'));
    }

    public function testFlushClearsEverything(): void
    {
        $hooks = new HookRegistry();
        $hooks->addAction('a', static fn () => null);
        $hooks->addFilter('b', static fn ($v) => $v);
        $hooks->flush();

        $this->assertFalse($hooks->hasAction('a'));
        $this->assertFalse($hooks->hasFilter('b'));
    }
}
