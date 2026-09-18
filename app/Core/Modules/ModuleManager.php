<?php

declare(strict_types=1);

namespace Nabilet\Core\Modules;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * Discovers, validates and orders modules.
 *
 * Responsibilities:
 *   - scan app/Modules and plugins for `module.json` manifests;
 *   - resolve a deterministic boot order from the `requires` graph;
 *   - refuse to boot when dependencies are missing, disabled, or form a cycle;
 *   - track enabled/disabled state.
 *
 * Why a dependency graph and not just a sorted list: modules genuinely depend on
 * each other (Telegram needs Notifications, YooKassa needs Payments). A
 * hard-coded load order in the kernel means every new module requires editing
 * core — exactly what ТЗ §2 forbids ("Без изменения файлов ядра").
 *
 * The ordering is deterministic: ready modules are picked by (priority, name), so
 * two runs on the same set always produce the same order. Non-determinism here
 * would make bugs unreproducible.
 *
 * Framework-agnostic: pure PHP, no Laravel, no container.
 *
 * @see docs/ARCHITECTURE.md — "Module system"
 */
final class ModuleManager
{
    /** @var array<string, ModuleManifest> */
    private array $manifests = [];

    /** @var array<string, bool> */
    private array $state = [];

    /** @var array<string, string> name => absolute path to the module directory */
    private array $locations = [];

    /** @param list<string> $scanPaths */
    public function __construct(private readonly array $scanPaths = [])
    {
    }

    /**
     * Discover every module under the configured scan paths.
     *
     * A missing scan path is not an error — a fresh install has no plugins yet.
     *
     * @return list<string> names of modules that failed to load (bad manifest)
     */
    public function discover(): array
    {
        $failed = [];

        foreach ($this->scanPaths as $basePath) {
            if (! is_dir($basePath)) {
                continue;
            }

            foreach (scandir($basePath) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $dir = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $entry;
                if (! is_dir($dir)) {
                    continue;
                }

                $manifestFile = $dir . DIRECTORY_SEPARATOR . 'module.json';
                if (! is_file($manifestFile)) {
                    continue;
                }

                $type = str_contains(str_replace('\\', '/', $dir), '/plugins/')
                    ? ModuleManifest::TYPE_PLUGIN
                    : ModuleManifest::TYPE_CORE;

                try {
                    $this->register(ModuleManifest::fromFile($manifestFile, $type), $dir);
                } catch (\Throwable) {
                    $failed[] = $entry;
                }
            }
        }

        ksort($this->manifests);

        return $failed;
    }

    public function register(ModuleManifest $manifest, ?string $path = null): void
    {
        $this->manifests[$manifest->name] = $manifest;
        $this->locations[$manifest->name] = $path ?? $manifest->path;
        $this->state[$manifest->name] = $manifest->enabled;
    }

    /** @return array<string, ModuleManifest> */
    public function all(): array
    {
        return $this->manifests;
    }

    /** @return array<string, ModuleManifest> */
    public function enabled(): array
    {
        return array_filter(
            $this->manifests,
            fn (ModuleManifest $m): bool => $this->isEnabled($m->name)
        );
    }

    public function get(string $name): ?ModuleManifest
    {
        return $this->manifests[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->manifests[$name]);
    }

    public function isEnabled(string $name): bool
    {
        return ($this->state[$name] ?? false) === true;
    }

    public function path(string $name): ?string
    {
        return $this->locations[$name] ?? null;
    }

    /**
     * Enable or disable a module.
     *
     * Refuses to enable a module whose dependencies are not themselves enabled —
     * otherwise the kernel boots a module that calls into a service which was
     * never registered, and the failure surfaces far from its cause.
     */
    public function setEnabled(string $name, bool $enabled): void
    {
        if (! $this->has($name)) {
            throw new DomainRuleViolation(
                sprintf('Unknown module "%s".', $name),
                'MODULE_NOT_FOUND'
            );
        }

        if ($enabled) {
            $missing = $this->unsatisfiedDependencies($name);
            if ($missing !== []) {
                throw new DomainRuleViolation(
                    sprintf('Cannot enable "%s": required module(s) not enabled: %s.', $name, implode(', ', $missing)),
                    'MODULE_DEPENDENCIES_UNSATISFIED',
                    ['module' => $name, 'missing' => $missing]
                );
            }
        } else {
            $dependents = $this->enabledDependents($name);
            if ($dependents !== []) {
                throw new DomainRuleViolation(
                    sprintf('Cannot disable "%s": required by enabled module(s): %s.', $name, implode(', ', $dependents)),
                    'MODULE_REQUIRED_BY_OTHERS',
                    ['module' => $name, 'dependents' => $dependents]
                );
            }
        }

        $this->state[$name] = $enabled;
    }

    /**
     * Deterministic boot order for all enabled modules.
     *
     * @return list<string>
     */
    public function bootOrder(): array
    {
        $problems = $this->validate();
        if ($problems !== []) {
            throw new DomainRuleViolation(
                'Module graph is invalid: ' . implode(' ', array_column($problems, 'message')),
                'MODULE_GRAPH_INVALID',
                ['problems' => $problems]
            );
        }

        $enabled = array_keys($this->enabled());
        $pending = array_fill_keys($enabled, true);

        /** @var array<string, list<string>> $dependencies */
        $dependencies = [];
        foreach ($enabled as $name) {
            $dependencies[$name] = array_values(array_filter(
                $this->manifests[$name]->requires,
                fn (string $dep): bool => isset($pending[$dep])
            ));
        }

        $order = [];
        while ($pending !== []) {
            $ready = [];
            foreach (array_keys($pending) as $name) {
                if ($dependencies[$name] === []) {
                    $ready[] = $name;
                }
            }

            if ($ready === []) {
                // validate() already rejects cycles; this is a defensive guard.
                throw new DomainRuleViolation(
                    'Circular module dependency detected among: ' . implode(', ', array_keys($pending)),
                    'MODULE_DEPENDENCY_CYCLE'
                );
            }

            // Deterministic tie-break: explicit priority first, then name.
            usort($ready, function (string $a, string $b): int {
                $byPriority = $this->manifests[$a]->priority <=> $this->manifests[$b]->priority;

                return $byPriority !== 0 ? $byPriority : strcmp($a, $b);
            });

            $next = $ready[0];
            $order[] = $next;
            unset($pending[$next]);

            foreach ($dependencies as $name => $deps) {
                $dependencies[$name] = array_values(array_filter($deps, fn (string $d): bool => $d !== $next));
            }
        }

        return $order;
    }

    /**
     * Full graph validation.
     *
     * @return list<array{module: string, type: string, message: string}>
     */
    public function validate(): array
    {
        $problems = [];

        foreach ($this->manifests as $name => $manifest) {
            if (! $this->isEnabled($name)) {
                continue;
            }

            foreach ($manifest->requires as $dependency) {
                if (! $this->has($dependency)) {
                    $problems[] = [
                        'module' => $name,
                        'type' => 'missing_dependency',
                        'message' => sprintf('"%s" requires "%s", which is not installed.', $name, $dependency),
                    ];
                    continue;
                }

                if (! $this->isEnabled($dependency)) {
                    $problems[] = [
                        'module' => $name,
                        'type' => 'disabled_dependency',
                        'message' => sprintf('"%s" requires "%s", which is disabled.', $name, $dependency),
                    ];
                }
            }
        }

        foreach ($this->detectCycles() as $cycle) {
            $problems[] = [
                'module' => $cycle[0],
                'type' => 'dependency_cycle',
                'message' => sprintf('Circular dependency: %s.', implode(' → ', [...$cycle, $cycle[0]])),
            ];
        }

        return $problems;
    }

    /**
     * Depth-first cycle detection over enabled modules.
     *
     * @return list<list<string>> each cycle as a node path
     */
    private function detectCycles(): array
    {
        $enabled = array_keys($this->enabled());
        $state = [];       // name => 1 (in progress) | 2 (done)
        $stack = [];
        $cycles = [];
        $seenCycles = [];

        $visit = function (string $name) use (&$visit, &$state, &$stack, &$cycles, &$seenCycles, $enabled): void {
            $state[$name] = 1;
            $stack[] = $name;

            foreach ($this->manifests[$name]->requires as $dependency) {
                if (! in_array($dependency, $enabled, true)) {
                    continue;
                }

                if (($state[$dependency] ?? 0) === 1) {
                    $start = array_search($dependency, $stack, true);
                    $cycle = array_slice($stack, $start === false ? 0 : $start);
                    sort($cycle);
                    $key = implode('|', $cycle);
                    if (! isset($seenCycles[$key])) {
                        $seenCycles[$key] = true;
                        $cycles[] = $cycle;
                    }
                    continue;
                }

                if (($state[$dependency] ?? 0) === 0) {
                    $visit($dependency);
                }
            }

            array_pop($stack);
            $state[$name] = 2;
        };

        foreach ($enabled as $name) {
            if (($state[$name] ?? 0) === 0) {
                $visit($name);
            }
        }

        return $cycles;
    }

    /** @return list<string> */
    public function unsatisfiedDependencies(string $name): array
    {
        $manifest = $this->get($name);
        if ($manifest === null) {
            return [];
        }

        return array_values(array_filter(
            $manifest->requires,
            fn (string $dep): bool => ! $this->isEnabled($dep)
        ));
    }

    /** @return list<string> enabled modules that require $name, sorted for determinism */
    public function enabledDependents(string $name): array
    {
        $dependents = [];
        foreach ($this->enabled() as $candidate => $manifest) {
            if (in_array($name, $manifest->requires, true)) {
                $dependents[] = $candidate;
            }
        }

        // Sorted so that error messages and guard decisions do not depend on
        // module discovery order.
        sort($dependents);

        return $dependents;
    }

    /** @return list<string> */
    public function scanPaths(): array
    {
        return $this->scanPaths;
    }
}
