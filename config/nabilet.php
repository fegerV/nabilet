<?php

declare(strict_types=1);

return [
    'version' => '1.0.0',
    
    'modules' => [
        'core' => \App\Modules\Core\Providers\CoreServiceProvider::class,
        'organizations' => \App\Modules\Organizations\Providers\OrganizationServiceProvider::class,
        'events' => \App\Modules\Events\Providers\EventServiceProvider::class,
        'sessions' => \App\Modules\Sessions\Providers\SessionServiceProvider::class,
        'venues' => \App\Modules\Venues\Providers\VenueServiceProvider::class,
        'inventory' => \App\Modules\Inventory\Providers\InventoryServiceProvider::class,
        'carts' => \App\Modules\Carts\Providers\CartServiceProvider::class,
        'orders' => \App\Modules\Orders\Providers\OrderServiceProvider::class,
        'payments' => \App\Modules\Payments\Providers\PaymentServiceProvider::class,
        'tickets' => \App\Modules\Tickets\Providers\TicketServiceProvider::class,
        'users' => \App\Modules\Users\Providers\UserServiceProvider::class,
    ],
    
    'ticket' => [
        'qr_secret' => env('TICKET_QR_SECRET', 'change-me-in-production'),
        'qr_ttl' => (int) env('TICKET_QR_TTL', 3600),
    ],
    
    'checkout' => [
        'hold_duration_minutes' => (int) env('CHECKOUT_HOLD_DURATION', 15),
        'max_items_per_order' => (int) env('CHECKOUT_MAX_ITEMS', 10),
    ],
    
    'payment' => [
        'providers' => explode(',', env('PAYMENT_PROVIDERS', 'stripe,kaspi')),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),
    ],
];
