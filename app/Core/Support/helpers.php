<?php

declare(strict_types=1);

use Nabilet\Core\Hooks\HookRegistry;

/**
 * Global hook functions — the WordPress-style API promised by ТЗ §6.
 *
 *     do_action('order.created', $order);
 *     $price = apply_filters('ticket.price', $price, $ticket);
 *
 * WHY GLOBAL FUNCTIONS AND NOT ONLY A SERVICE
 * The spec's extension contract is written in this vocabulary, and plugin authors
 * expect it. A plugin's README saying `add_action('order.paid', ...)` is immediately
 * understandable; requiring `$container->get(HookRegistry::class)->addAction(...)`
 * raises the barrier for exactly the audience plugins are written for.
 *
 * The functions are thin delegations to the HookRegistry singleton, so there is one
 * implementation and one behaviour.
 *
 * SAFETY NOTE: `do_action` propagates exceptions from listeners. In a checkout flow
 * prefer `do_action_safe()` — a third-party plugin throwing must not abort a sale.
 */

if (! function_exists('nabilet_hooks')) {
    /**
     * Resolve the shared hook registry.
     *
     * Falls back to a standalone instance when the container is not booted, which
     * makes the functions usable from plain scripts and tests.
     */
    function nabilet_hooks(): HookRegistry
    {
        static $fallback = null;

        if (function_exists('app')) {
            try {
                $registry = app(HookRegistry::class);
                if ($registry instanceof HookRegistry) {
                    return $registry;
                }
            } catch (\Throwable) {
                // Container not ready — use the fallback below.
            }
        }

        return $fallback ??= new HookRegistry();
    }
}

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        nabilet_hooks()->addAction($hook, $callback, $priority, $acceptedArgs);
    }
}

if (! function_exists('remove_action')) {
    function remove_action(string $hook, callable $callback, int $priority = 10): bool
    {
        return nabilet_hooks()->removeAction($hook, $callback, $priority);
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        nabilet_hooks()->doAction($hook, ...$args);
    }
}

if (! function_exists('do_action_safe')) {
    /**
     * Fire an action with per-listener isolation.
     *
     * Use this in money-touching flows. A listener that throws is reported through
     * the error handler and skipped; the remaining listeners still run and the
     * checkout completes.
     *
     * @param callable(string, \Throwable): void|null $onError
     */
    function do_action_safe(string $hook, ?callable $onError = null, mixed ...$args): void
    {
        $handler = $onError ?? static function (string $hook, \Throwable $e): void {
            if (function_exists('report')) {
                report($e);
            }
        };

        nabilet_hooks()->doActionSafely($hook, $handler, ...$args);
    }
}

if (! function_exists('has_action')) {
    function has_action(string $hook): bool
    {
        return nabilet_hooks()->hasAction($hook);
    }
}

if (! function_exists('did_action')) {
    function did_action(string $hook): int
    {
        return nabilet_hooks()->didAction($hook);
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        nabilet_hooks()->addFilter($hook, $callback, $priority, $acceptedArgs);
    }
}

if (! function_exists('remove_filter')) {
    function remove_filter(string $hook, callable $callback, int $priority = 10): bool
    {
        return nabilet_hooks()->removeFilter($hook, $callback, $priority);
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return nabilet_hooks()->applyFilters($hook, $value, ...$args);
    }
}

if (! function_exists('has_filter')) {
    function has_filter(string $hook): bool
    {
        return nabilet_hooks()->hasFilter($hook);
    }
}
