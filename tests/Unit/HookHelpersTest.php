<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Hooks\HookRegistry;
use Nabilet\Tests\Support\TestCase;

/**
 * The global hook API is the documented extension contract (ТЗ §6), so it needs its
 * own tests: plugin authors will call these functions, and they must behave exactly
 * as the service does.
 *
 * In this environment `app()` does not exist, so the helpers fall back to a
 * standalone registry — which is also the behaviour plain scripts and tests get.
 */
final class HookHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        nabilet_hooks()->flush();
    }

    public function testHelpersAreDefined(): void
    {
        foreach ([
            'add_action', 'remove_action', 'do_action', 'do_action_safe', 'has_action', 'did_action',
            'add_filter', 'remove_filter', 'apply_filters', 'has_filter', 'nabilet_hooks',
        ] as $function) {
            $this->assertTrue(function_exists($function), $function . '() must be defined');
        }
    }

    public function testHelpersShareOneRegistry(): void
    {
        $this->assertTrue(nabilet_hooks() === nabilet_hooks());
        $this->assertTrue(nabilet_hooks() instanceof HookRegistry);
    }

    public function testDoActionFiresRegisteredListener(): void
    {
        $received = null;

        add_action('order.created', function ($order) use (&$received): void {
            $received = $order;
        });

        do_action('order.created', ['id' => 1234]);

        $this->assertSame(['id' => 1234], $received);
        $this->assertSame(1, did_action('order.created'));
        $this->assertTrue(has_action('order.created'));
    }

    public function testApplyFiltersTransformsValue(): void
    {
        add_filter('ticket.price', static fn (int $price): int => $price + 300, 20);
        add_filter('ticket.price', static fn (int $price): int => $price * 2, 10);

        $this->assertSame(10300, apply_filters('ticket.price', 5000));
        $this->assertTrue(has_filter('ticket.price'));
    }

    public function testApplyFiltersPassesExtraContext(): void
    {
        $captured = null;

        add_filter('event.meta', function (array $meta, $event) use (&$captured): array {
            $captured = $event;
            $meta['touched'] = true;

            return $meta;
        }, 10, 2);

        $result = apply_filters('event.meta', ['title' => 'Hamlet'], 'EV-1');

        $this->assertSame('EV-1', $captured);
        $this->assertTrue($result['touched']);
    }

    public function testRemoveActionWorksThroughHelpers(): void
    {
        $calls = 0;
        $listener = function () use (&$calls): void {
            $calls++;
        };

        add_action('ticket.issued', $listener);
        do_action('ticket.issued');
        $this->assertSame(1, $calls);

        $this->assertTrue(remove_action('ticket.issued', $listener));
        do_action('ticket.issued');
        $this->assertSame(1, $calls);
    }

    /**
     * A plugin throwing must not abort a checkout. do_action_safe() is the variant
     * used in money-touching flows for exactly this reason.
     */
    public function testDoActionSafeIsolatesFailuresAndReportsThem(): void
    {
        $errors = [];
        $laterListenerRan = false;

        add_action('order.paid', function (): void {
            throw new \RuntimeException('third-party plugin exploded');
        }, 5);

        add_action('order.paid', function () use (&$laterListenerRan): void {
            $laterListenerRan = true;
        }, 10);

        do_action_safe('order.paid', function (string $hook, \Throwable $e) use (&$errors): void {
            $errors[] = $hook . ': ' . $e->getMessage();
        }, 'O-1');

        $this->assertTrue($laterListenerRan, 'remaining listeners must still run');
        $this->assertSame(['order.paid: third-party plugin exploded'], $errors);
    }

    public function testPlainDoActionPropagatesExceptions(): void
    {
        add_action('order.created', function (): void {
            throw new \RuntimeException('boom');
        });

        $this->assertThrows(\RuntimeException::class, static fn () => do_action('order.created'));
    }

    public function testPriorityOrderingIsRespectedThroughHelpers(): void
    {
        $order = [];

        add_action('test.hook', function () use (&$order): void {
            $order[] = 'default';
        }, 10);
        add_action('test.hook', function () use (&$order): void {
            $order[] = 'early';
        }, 1);

        do_action('test.hook');

        $this->assertSame(['early', 'default'], $order);
    }
}
