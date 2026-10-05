<?php

declare(strict_types=1);

namespace Nabilet\Modules\Tickets\Providers;

use Illuminate\Support\ServiceProvider;

class TicketServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // API routes are included by routes/api.php under the /api/v1 prefix.
        // Loading them here as well would register duplicate unprefixed routes.
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'tickets');
    }
}
