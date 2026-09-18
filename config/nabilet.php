<?php

declare(strict_types=1);

/**
 * NABILET Core kernel configuration.
 *
 * Everything here has an environment-variable equivalent in .env.example, so a
 * deployment can be reconfigured without touching code. Defaults are chosen so the
 * system is safe out of the box: no Redis requirement, no feature that needs
 * explicit consent enabled by default.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Module system (ТЗ §4, §5, §81)
    |--------------------------------------------------------------------------
    */
    'modules' => [
        // Scanned for `module.json` manifests. A missing directory is not an error.
        'paths' => [
            'core' => base_path('app/Modules'),
            'plugins' => base_path('plugins'),
        ],

        // Modules that may never be disabled: disabling them would leave the
        // system without its identity or tenancy layer.
        'required' => ['organizations', 'auth', 'users'],

        'cache_manifest' => env('NABILET_CACHE_MODULES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticketing core (ТЗ §24, §84)
    |--------------------------------------------------------------------------
    */
    'ticketing' => [
        // How long a selected seat stays reserved before payment.
        'hold_ttl_seconds' => (int) env('NABILET_HOLD_TTL', 600),

        // Grace period after expiry, so a buyer mid-checkout is not beaten by a
        // clock tick. The sweeper releases holds only after expires_at + grace.
        'hold_grace_seconds' => (int) env('NABILET_HOLD_GRACE', 30),

        // Sweeper batch size — bounds the transaction so the job cannot lock the
        // whole seat_holds table on a busy sale.
        'hold_sweep_batch' => (int) env('NABILET_HOLD_SWEEP_BATCH', 500),

        // Anti-scalping guard.
        'max_seats_per_order' => (int) env('NABILET_MAX_SEATS_PER_ORDER', 8),

        // Cart lifetime is derived from the holds it contains; this is the ceiling.
        'cart_ttl_seconds' => (int) env('NABILET_CART_TTL', 1800),
    ],

    /*
    |--------------------------------------------------------------------------
    | QR signing (ТЗ §30)
    |--------------------------------------------------------------------------
    | The secret must be at least 32 bytes; QrSigner refuses to construct otherwise.
    | Rotating it invalidates every issued QR code, so `previous_secrets` exists to
    | allow verification of tickets issued before a rotation.
    */
    'qr' => [
        'secret' => env('NABILET_QR_SECRET', ''),
        'key_id' => env('NABILET_QR_KEY_ID', 'k1'),
        'previous_secrets' => array_filter(explode(',', (string) env('NABILET_QR_PREVIOUS_SECRETS', ''))),

        // Offline bundles expire so a stolen tablet cannot validate tickets forever.
        'offline_bundle_ttl_hours' => (int) env('NABILET_OFFLINE_BUNDLE_TTL', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payments (ТЗ §27, §28)
    |--------------------------------------------------------------------------
    */
    'payments' => [
        'default_provider' => env('PAYMENTS_DEFAULT_PROVIDER', 'yookassa'),

        'providers' => [
            'yookassa' => [
                'shop_id' => env('YOOKASSA_SHOP_ID'),
                'secret_key' => env('YOOKASSA_SECRET_KEY'),
                'return_url' => env('YOOKASSA_RETURN_URL'),
                'webhook_ip_allowlist' => array_filter(
                    explode(',', (string) env('YOOKASSA_WEBHOOK_IP_ALLOWLIST', ''))
                ),
            ],
        ],

        // Webhook handling: the provider event id is the dedup key (see
        // payment_transactions.provider_event_id).
        'webhook' => [
            'verify_signature' => true,
            'store_raw_payload' => true,
            'max_clock_skew_seconds' => 300,
        ],

        // Payment deadline. Drives order -> expired and hold release.
        'payment_window_seconds' => (int) env('NABILET_PAYMENT_WINDOW', 600),

        // Service fee. Kept here (not hard-coded in a service) so it can be
        // overridden per organization via the settings table.
        'service_fee' => [
            'enabled' => (bool) env('NABILET_SERVICE_FEE_ENABLED', false),
            'percent_basis_points' => (int) env('NABILET_SERVICE_FEE_BP', 0),
            'fixed_minor' => (int) env('NABILET_SERVICE_FEE_FIXED', 0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy (ТЗ §10)
    |--------------------------------------------------------------------------
    */
    'tenancy' => [
        // Fail-closed by default: a tenant-scoped query without a context throws
        // instead of returning every organization's rows.
        'fail_closed' => true,

        // Paths allowed to run without an organization context.
        'system_paths' => ['install', 'health', 'ready', 'api/v1/webhooks/*', 'sitemap*.xml'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching (ТЗ §59)
    |--------------------------------------------------------------------------
    | Multi-level: request (L1) -> Redis (L2) -> database (L3) -> HTTP/CDN (L4).
    |
    | NEVER cached: seat availability, holds, payment state, check-in state. Stale
    | availability data is worse than a slow query — it sells seats twice.
    */
    'cache' => [
        'enabled' => (bool) env('NABILET_CACHE_ENABLED', true),

        'ttl' => [
            'settings' => 3600,
            'event' => 300,
            'event_list' => 120,
            'venue' => 900,
            'hall_schema' => 1800,
            'seo_meta' => 3600,
            'translations' => 3600,
        ],

        // Explicit deny-list, checked by the cache layer. Documents the intent so a
        // future contributor cannot "optimise" seat availability into the cache.
        'never_cache' => [
            'inventory_items',
            'seat_holds',
            'payments',
            'tickets',
            'ticket_scans',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (ТЗ §58)
    |--------------------------------------------------------------------------
    | Per-route limits. The checker API gets a deliberately high limit: an operator
    | scanning a queue of 500 people must never be throttled.
    */
    'rate_limits' => [
        'login' => ['attempts' => 5, 'decay_seconds' => 60],
        'register' => ['attempts' => 10, 'decay_seconds' => 60],
        'password_reset' => ['attempts' => 3, 'decay_seconds' => 300],
        'checkout' => ['attempts' => 20, 'decay_seconds' => 60],
        'payment' => ['attempts' => 10, 'decay_seconds' => 60],
        'api' => ['attempts' => 120, 'decay_seconds' => 60],
        'checkin_validate' => ['attempts' => 600, 'decay_seconds' => 60],
        'webhooks_incoming' => ['attempts' => 300, 'decay_seconds' => 60],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks / integrations (ТЗ §46, §47)
    |--------------------------------------------------------------------------
    */
    'webhooks' => [
        'secret' => env('NABILET_WEBHOOK_SECRET', ''),
        'signature_header' => 'X-NABILET-Signature',
        'max_retries' => 3,
        'timeout_seconds' => 10,
        // Exponential backoff, in seconds, indexed by attempt number.
        'backoff' => [30, 300, 1800],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature flags (ТЗ §55, §92-95)
    |--------------------------------------------------------------------------
    | Features that involve tracking or third-party data are OFF by default and must
    | be switched on deliberately, together with the privacy module's consent flow.
    */
    'features' => [
        'embed' => (bool) env('FEATURE_EMBED', true),
        'telegram' => (bool) env('FEATURE_TELEGRAM', false),
        'pwa' => (bool) env('FEATURE_PWA', true),
        'ab_testing' => (bool) env('FEATURE_AB_TESTING', false),
        'heatmaps' => (bool) env('FEATURE_HEATMAPS', false),
        'session_recording' => (bool) env('FEATURE_SESSION_RECORDING', false),
        'ai' => (bool) env('FEATURE_AI', false),
        'offline_checker' => (bool) env('FEATURE_OFFLINE_CHECKER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hall schema editor (ТЗ §63, §64)
    |--------------------------------------------------------------------------
    */
    'editor' => [
        'autosave_debounce_ms' => 500,
        'max_objects_warning' => 2000,
        'target_fps' => 60,
        'grid_sizes' => [5, 10, 20, 25],
    ],

    /*
    |--------------------------------------------------------------------------
    | Installer (ТЗ §7)
    |--------------------------------------------------------------------------
    */
    'installer' => [
        'lock_file' => 'install.lock',
        'enabled' => env('NABILET_INSTALLER_ENABLED', true),
        'requirements' => [
            'php' => '8.3.0',
            'extensions' => ['pdo', 'openssl', 'mbstring', 'json', 'curl', 'gd', 'zip'],
        ],
    ],
];
