<?php

declare(strict_types=1);

namespace Nabilet\Core;

use Illuminate\Support\ServiceProvider;
use Nabilet\Core\Hooks\HookRegistry;
use Nabilet\Core\Modules\ModuleManager;
use Nabilet\Core\Tenancy\OrganizationContext;

/**
 * The kernel's single Laravel entry point.
 *
 * Responsibilities, in order:
 *   1. Bind the three kernel singletons (hooks, modules, tenant context).
 *   2. Discover modules and boot them in dependency order.
 *   3. Register each module's own service provider.
 *
 * WHY MODULE BOOTING LIVES HERE AND NOT IN config/app.php
 * A hand-maintained provider list in config/app.php means every new module requires
 * editing core configuration — the exact thing ТЗ §2 forbids ("Без изменения файлов
 * ядра"). Boot order is derived from each module's `requires` graph instead, so
 * dropping a module directory in place is enough.
 */
final class NabiletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons: the hook bus and tenant context must be the SAME instance for
        // the whole request, otherwise a plugin registers a listener on one registry
        // while the application fires events on another.
        $this->app->singleton(HookRegistry::class, static fn (): HookRegistry => new HookRegistry());

        $this->app->singleton(OrganizationContext::class, static fn (): OrganizationContext => new OrganizationContext());

        $this->app->singleton(ModuleManager::class, function ($app): ModuleManager {
            return new ModuleManager([
                base_path('app/Modules'),
                base_path('plugins'),
            ]);
        });

        $this->mergeConfigFrom(__DIR__ . '/../../config/nabilet.php', 'nabilet');
    }

    public function boot(): void
    {
        $this->publishConfig();

        $manager = $this->app->make(ModuleManager::class);
        $manager->discover();

        // Persisted enable/disable state wins over the manifest default, so an
        // administrator can turn a module off without editing its manifest.
        $this->applyPersistedState($manager);

        foreach ($manager->bootOrder() as $name) {
            $manifest = $manager->get($name);
            if ($manifest === null) {
                continue;
            }

            $providerClass = $manifest->serviceProviderClass();
            if ($providerClass !== null) {
                $this->app->register($providerClass);
            }
        }
    }

    /**
     * Overlay the `modules` table onto the discovered manifests.
     *
     * Wrapped defensively: during installation the table does not exist yet, and a
     * fresh install must not fail because module state cannot be read.
     */
    private function applyPersistedState(ModuleManager $manager): void
    {
        try {
            if (! $this->app->bound('db')) {
                return;
            }

            $rows = $this->app['db']->table('modules')->select('name', 'is_enabled')->get();

            foreach ($rows as $row) {
                $name = (string) $row->name;

                if (! $manager->has($name)) {
                    continue;
                }

                $enabled = (bool) $row->is_enabled;

                if ($enabled !== $manager->isEnabled($name)) {
                    try {
                        $manager->setEnabled($name, $enabled);
                    } catch (\Throwable) {
                        // A module whose dependencies are missing stays disabled
                        // rather than taking the whole application down.
                    }
                }
            }
        } catch (\Throwable) {
            // Table missing (pre-install) or database unavailable — manifest
            // defaults apply.
        }
    }

    private function publishConfig(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../../config/nabilet.php' => config_path('nabilet.php'),
        ], 'nabilet-config');
    }
}
