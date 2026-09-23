<?php

declare(strict_types=1);

return [
    'version' => '1.0.0',
    
    'modules' => [
        'core' => \Nabilet\Modules\Core\Providers\CoreServiceProvider::class,
        'events' => \Nabilet\Modules\Events\Providers\EventServiceProvider::class,
        'sessions' => \Nabilet\Modules\Sessions\Providers\SessionServiceProvider::class,
        'venues' => \Nabilet\Modules\Venues\Providers\VenueServiceProvider::class,
        'inventory' => \Nabilet\Modules\Inventory\Providers\InventoryServiceProvider::class,
        'carts' => \Nabilet\Modules\Cart\Providers\CartServiceProvider::class,
        'orders' => \Nabilet\Modules\Orders\Providers\OrderServiceProvider::class,
        'payments' => \Nabilet\Modules\Payments\Providers\PaymentServiceProvider::class,
        'tickets' => \Nabilet\Modules\Tickets\Providers\TicketServiceProvider::class,
        'users' => \Nabilet\Modules\Users\Providers\UsersServiceProvider::class,
        'auth' => \Nabilet\Modules\Auth\Providers\AuthServiceProvider::class,
        'admin' => \Nabilet\Modules\Admin\Providers\AdminServiceProvider::class,
        'analytics' => \Nabilet\Modules\Analytics\Providers\AnalyticsServiceProvider::class,
        'ab_testing' => \Nabilet\Modules\AbTesting\Providers\AbTestingServiceProvider::class,
        'ai' => \Nabilet\Modules\Ai\Providers\AiServiceProvider::class,
        'backups' => \Nabilet\Modules\Backups\Providers\BackupsServiceProvider::class,
        'checkin' => \Nabilet\Modules\Checkin\Providers\CheckinServiceProvider::class,
        'content' => \Nabilet\Modules\Content\Providers\ContentServiceProvider::class,
        'embed' => \Nabilet\Modules\Embed\Providers\EmbedServiceProvider::class,
        'hall_schemas' => \Nabilet\Modules\HallSchemas\Providers\HallSchemasServiceProvider::class,
        'heatmaps' => \Nabilet\Modules\Heatmaps\Providers\HeatmapsServiceProvider::class,
        'installer' => \Nabilet\Modules\Installer\Providers\InstallerServiceProvider::class,
        'localization' => \Nabilet\Modules\Localization\Providers\LocalizationServiceProvider::class,
        'media' => \Nabilet\Modules\Media\Providers\MediaServiceProvider::class,
        'notifications' => \Nabilet\Modules\Notifications\Providers\NotificationsServiceProvider::class,
        'pricing' => \Nabilet\Modules\Pricing\Providers\PricingServiceProvider::class,
        'privacy' => \Nabilet\Modules\Privacy\Providers\PrivacyServiceProvider::class,
        'security' => \Nabilet\Modules\Security\Providers\SecurityServiceProvider::class,
        'seo' => \Nabilet\Modules\Seo\Providers\SeoServiceProvider::class,
        'system' => \Nabilet\Modules\System\Providers\SystemServiceProvider::class,
        'telegram' => \Nabilet\Modules\Telegram\Providers\TelegramServiceProvider::class,
        'webhooks' => \Nabilet\Modules\Webhooks\Providers\WebhooksServiceProvider::class,
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
        'providers' => explode(',', env('PAYMENT_PROVIDERS', 'yookassa')),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),

        // Провайдер по умолчанию для новых платежей. `PaymentService` берёт его
        // отсюда: колонка `payments.provider` объявлена NOT NULL, поэтому «пусто»
        // здесь означало бы отказ вставки, а не «без провайдера».
        'default_provider' => env('PAYMENTS_DEFAULT_PROVIDER', 'yookassa'),

        // YooKassa. Обе строки обязательны для конструктора `YooKassaProvider`:
        // контейнер не может вывести их сам, поэтому провайдер создаётся вручную
        // в `PaymentService::makeYooKassa()`, а не через `app()`.
        'yookassa' => [
            'shop_id' => env('YOOKASSA_SHOP_ID'),
            'secret_key' => env('YOOKASSA_SECRET_KEY'),
            'return_url' => env('YOOKASSA_RETURN_URL'),
            // YooKassa не подписывает уведомления HMAC-ом: основной механизм —
            // allowlist IP-адресов. Секрет здесь нужен только для установок,
            // которые проксируют уведомления и теряют исходный IP.
            'webhook_secret' => env('YOOKASSA_WEBHOOK_SECRET'),
            'webhook_ip_allowlist' => array_values(array_filter(
                explode(',', (string) env('YOOKASSA_WEBHOOK_IP_ALLOWLIST', ''))
            )),
            'base_url' => env('YOOKASSA_BASE_URL', 'https://api.yookassa.ru/v3'),
        ],

        // Эти два подписывают уведомления HMAC-SHA256, поэтому секрет обязателен:
        // без него `WebhookSignatureVerifier` не примет ни одного уведомления.
        'stripe' => [
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
        'kaspi' => [
            'webhook_secret' => env('KASPI_WEBHOOK_SECRET'),
        ],
    ],
];
