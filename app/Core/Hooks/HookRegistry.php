<?php

declare(strict_types=1);

namespace Nabilet\Core\Hooks;

/**
 * WordPress-style hook bus: actions (fire-and-forget) + filters (value transformation).
 *
 * This is the PUBLIC extension contract of NABILET Core. Third-party plugins and
 * modules extend behaviour exclusively through hooks — never by editing core files.
 *
 * Framework-agnostic on purpose: no Laravel, no container, no DB. That makes the
 * extension contract testable in isolation and reusable by the Android Checker's
 * edge service and the Embed bundle.
 *
 * @see docs/ARCHITECTURE.md — "Extension model"
 */
final class HookRegistry
{
    /** @var array<string, array<int, list<array{callback: callable, acceptedArgs: int}>>> */
    private array $actions = [];

    /** @var array<string, array<int, list<array{callback: callable, acceptedArgs: int}>>> */
    private array $filters = [];

    /** @var array<string, int> */
    private array $didAction = [];

    /** @var array<string, int> */
    private array $didFilter = [];

    // ---------------------------------------------------------------- actions

    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->actions[$hook][$priority][] = [
            'callback' => $callback,
            'acceptedArgs' => max(1, $acceptedArgs),
        ];
    }

    public function removeAction(string $hook, callable $callback, int $priority = 10): bool
    {
        return $this->remove($this->actions, $hook, $callback, $priority);
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        $this->didAction[$hook] = ($this->didAction[$hook] ?? 0) + 1;

        foreach ($this->sortedCallbacks($this->actions, $hook) as $entry) {
            $entry['callback'](...array_slice($args, 0, $entry['acceptedArgs']));
        }
    }

    /**
     * Fire an action inside its own isolation boundary. A misbehaving listener
     * (a third-party plugin throwing) must never take down a checkout.
     *
     * @param  callable(string, \Throwable): void  $onError
     */
    public function doActionSafely(string $hook, callable $onError, mixed ...$args): void
    {
        foreach ($this->sortedCallbacks($this->actions, $hook) as $entry) {
            try {
                $entry['callback'](...array_slice($args, 0, $entry['acceptedArgs']));
            } catch (\Throwable $e) {
                $onError($hook, $e);
            }
        }

        $this->didAction[$hook] = ($this->didAction[$hook] ?? 0) + 1;
    }

    // ---------------------------------------------------------------- filters

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->filters[$hook][$priority][] = [
            'callback' => $callback,
            'acceptedArgs' => max(1, $acceptedArgs),
        ];
    }

    public function removeFilter(string $hook, callable $callback, int $priority = 10): bool
    {
        return $this->remove($this->filters, $hook, $callback, $priority);
    }

    /**
     * Pass $value through every registered filter in priority order.
     * The first argument to each callback is always the value being filtered.
     */
    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $this->didFilter[$hook] = ($this->didFilter[$hook] ?? 0) + 1;

        foreach ($this->sortedCallbacks($this->filters, $hook) as $entry) {
            $value = $entry['callback'](
                $value,
                ...array_slice($args, 0, max(0, $entry['acceptedArgs'] - 1))
            );
        }

        return $value;
    }

    // ------------------------------------------------------------ inspection

    public function hasAction(string $hook): bool
    {
        return ! empty($this->actions[$hook]);
    }

    public function hasFilter(string $hook): bool
    {
        return ! empty($this->filters[$hook]);
    }

    public function didAction(string $hook): int
    {
        return $this->didAction[$hook] ?? 0;
    }

    public function didFilter(string $hook): int
    {
        return $this->didFilter[$hook] ?? 0;
    }

    /** @return list<string> */
    public function registeredActions(): array
    {
        $hooks = array_keys($this->actions);
        sort($hooks);

        return $hooks;
    }

    /** @return list<string> */
    public function registeredFilters(): array
    {
        $hooks = array_keys($this->filters);
        sort($hooks);

        return $hooks;
    }

    /**
     * Remove every callback. Used between tests and when reloading plugins.
     */
    public function flush(): void
    {
        $this->actions = [];
        $this->filters = [];
        $this->didAction = [];
        $this->didFilter = [];
    }

    // --------------------------------------------------------------- internals

    /**
     * @param  array<string, array<int, list<array{callback: callable, acceptedArgs: int}>>>  $pool
     * @return list<array{callback: callable, acceptedArgs: int}>
     */
    private function sortedCallbacks(array $pool, string $hook): array
    {
        if (empty($pool[$hook])) {
            return [];
        }

        $byPriority = $pool[$hook];
        ksort($byPriority, SORT_NUMERIC);

        $flat = [];
        foreach ($byPriority as $entries) {
            foreach ($entries as $entry) {
                $flat[] = $entry;
            }
        }

        return $flat;
    }

    /**
     * @param  array<string, array<int, list<array{callback: callable, acceptedArgs: int}>>>  $pool
     */
    private function remove(array &$pool, string $hook, callable $callback, int $priority): bool
    {
        if (empty($pool[$hook][$priority])) {
            return false;
        }

        $removed = false;
        $pool[$hook][$priority] = array_values(array_filter(
            $pool[$hook][$priority],
            static function (array $entry) use ($callback, &$removed): bool {
                // Callables are compared by identity where possible, otherwise by
                // string form — mirrors WordPress semantics for closures/objects.
                $isSame = $entry['callback'] === $callback
                    || (is_string($entry['callback']) && $entry['callback'] === $callback);

                if ($isSame) {
                    $removed = true;

                    return false;
                }

                return true;
            }
        ));

        if (empty($pool[$hook][$priority])) {
            unset($pool[$hook][$priority]);
        }

        if (empty($pool[$hook])) {
            unset($pool[$hook]);
        }

        return $removed;
    }
}
