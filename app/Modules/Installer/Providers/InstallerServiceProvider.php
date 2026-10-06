<?php

declare(strict_types=1);

namespace Nabilet\Modules\Installer\Providers;

use Illuminate\Support\ServiceProvider;

class InstallerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind installer services
    }

    public function boot(): void
    {
        // Routes are loaded by routes/api.php (it requires this module's web.php
        // first, so the installer is reachable before any other module). Loading
        // them again here would double-register the named routes, so we only mount
        // the views here.
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'installer');
    }
}
