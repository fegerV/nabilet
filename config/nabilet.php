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
        'users' => \App\Modules\Users\Providers\UsersServiceProvider::class,
        'auth' => \App\Modules\Auth\Providers\AuthServiceProvider::class,
        'admin' => \App\Modules\Admin\Providers\AdminServiceProvider::class,
        'analytics' => \App\Modules\Analytics\Providers\AnalyticsServiceProvider::class,
        'ab_testing' => \App\Modules\AbTesting\Providers\AbTestingServiceProvider::class,
        'ai' => \App\Modules\Ai\Providers\AiServiceProvider::class,
        'backups' => \App\Modules\Backups\Providers\BackupsServiceProvider::class,
        'checkin' => \App\Modules\Checkin\Providers\CheckinServiceProvider::class,
        'content' => \App\Modules\Content\Providers\ContentServiceProvider::class,
        'embed' => \App\Modules\Embed\Providers\EmbedServiceProvider::class,
        'hall_schemas' => \App\Modules\HallSchemas\Providers\HallSchemasServiceProvider::class,
        'heatmaps' => \App\Modules\Heatmaps\Providers\HeatmapsServiceProvider::class,
        'installer' => \App\Modules\Installer\Providers\InstallerServiceProvider::class,
        'localization' => \App\Modules\Localization\Providers\LocalizationServiceProvider::class,
        'media' => \App\Modules\Media\Providers\MediaServiceProvider::class,
        'notifications' => \App\Modules\Notifications\Providers\NotificationsServiceProvider::class,
        'pricing' => \App\Modules\Pricing\Providers\PricingServiceProvider::class,
        'privacy' => \App\Modules\Privacy\Providers\PrivacyServiceProvider::class,
        'security' => \App\Modules\Security\Providers\SecurityServiceProvider::class,
        'seo' => \App\Modules\Seo\Providers\SeoServiceProvider::class,
        'system' => \App\Modules\System\Providers\SystemServiceProvider::class,
        'telegram' => \App\Modules\Telegram\Providers\TelegramServiceProvider::class,
        'webhooks' => \App\Modules\Webhooks\Providers\WebhooksServiceProvider::class,
    ],
    
    'ticket' => [
        'qr_secret' => env('TICKET_QR_SECRET'),
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
