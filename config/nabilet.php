<?php

declare(strict_types=1);

return [
    'version' => '1.0.0',

    /*
     * Создавать ли демо-данные сидера `NabiletAdminSeeder` (организация
     * «NABILET Demo» и три учётки с паролями из исходников, включая
     * admin@nabilet.local/admin123 с полной ролью администратора).
     *
     * Роли и права сидируются ВСЕГДА — без них RBAC не работает. А демо-учётки
     * на боевом стенде означают открытую админку, поэтому установщик
     * (`db:seed --force`) создаёт их только вне production или при явном
     * NABILET_SEED_DEMO=true (аудит установщика B-2).
     */
    'seed_demo' => env('NABILET_SEED_DEMO', false),

    /*
     * Организация-владелец стартового каталога площадок Сургута
     * (`Database\Seeders\SurgutVenuesSeeder`).
     *
     * По умолчанию НЕ ЗАДАНА, и сидер определяет адресата сам: организация
     * администратора, иначе самая первая организация. При установке на хостинг
     * это всегда одна и та же запись — её создаёт установщик на шаге 5, до
     * `db:seed` — поэтому дополнительная настройка не нужна.
     *
     * Ключ нужен для нестандартного случая: в базе уже несколько организаций, и
     * стартовые площадки должны лечь в конкретную, а не в ту, что окажется
     * первой. `NABILET_SEED_VENUES_ORGANIZATION_ID=3`.
     */
    'seed_venues_organization_id' => env('NABILET_SEED_VENUES_ORGANIZATION_ID'),

    /*
     * Пароли пользователей (аудит безопасности S-09).
     *
     * `check_breaches` включает проверку пароля по базе утечек HaveIBeenPwned
     * (`Password::uncompromised()`). ПО УМОЛЧАНИЮ ВЫКЛЮЧЕНА: это внешний HTTP-вызов
     * внутри валидации, то есть регистрация начинает зависеть от доступности
     * чужого сервиса, а тесты — от наличия интернета. Laravel при сетевой ошибке
     * считает пароль «чистым», поэтому включение безопасно, но это решение деплоя:
     * `NABILET_PASSWORD_BREACH_CHECK=true`.
     *
     * Минимальная длина и требования к составу заданы в
     * `Nabilet\Modules\Auth\Validation\PasswordRules` — одной константой на весь
     * проект, чтобы политика не разошлась между регистрацией и админкой.
     */
    'auth' => [
        'password' => [
            'check_breaches' => env('NABILET_PASSWORD_BREACH_CHECK', false),
        ],
    ],

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
        // Секрет подписи QR-билетов. Фолбэка на APP_KEY НЕТ намеренно (см.
        // Modules/Tickets/Services/QrSignerFactory): один утёкший ключ дал бы
        // возможность подписывать любые билеты, а его ротация обнулила бы все
        // выпущенные. `NABILET_QR_SECRET` поддержан как историческое имя.
        'qr_secret' => env('TICKET_QR_SECRET') ?: env('NABILET_QR_SECRET'),
        'qr_ttl' => (int) env('TICKET_QR_TTL', 3600),
    ],

    /*
     * Медиа: куда складывать загруженные файлы.
     *
     * ЗАЧЕМ ОТДЕЛЬНЫЙ КЛЮЧ, А НЕ `filesystems.default`
     *
     * `filesystems.default` в этом проекте равен `local`, а диск `local`
     * указывает на `storage/app/private` — каталог, который веб-сервер не
     * отдаёт. Файл, записанный туда, недоступен по URL вообще: запись в базе
     * есть, картинки нет. Публичные файлы (афиши, галерея, логотипы) обязаны
     * идти на диск `public` (`storage/app/public` → `/storage/...`), и это
     * должно быть решением установки, а не значением по умолчанию чужого ключа.
     *
     * Установка с несколькими узлами переопределяет это на `s3`: локальный диск
     * не виден другим узлам, и медиа «пропадает» на половине запросов.
     */
    'media' => [
        'disk' => env('MEDIA_DISK', 'public'),
    ],

    /*
     * События: автоматическое завершение прошедших мероприятий.
     *
     * `events:sync-status` переводит `published → completed`, когда все сеансы
     * события уже закончились. Без этого прошедший концерт оставался
     * «запланированным»: JSON-LD отдавал `eventStatus: EventScheduled` и
     * `availability: InStock`, то есть разметка обещала Google и Яндексу
     * событие в будущем с билетами в наличии. Это не косметика — Google
     * понижает доверие к разметке, которая расходится с реальностью, а
     * rich-результат начинает показывать прошедшую дату как предстоящую.
     *
     * ЗАЧЕМ ПАРАМЕТР, А НЕ ПРОСТО `ends_at`
     *   `sessions.ends_at` объявлен NULLABLE: администратор вправе указать только
     *   начало. Сеанс без конца нельзя считать закончившимся в момент начала —
     *   трёхчасовой концерт объявлялся бы «состоявшимся», пока он ещё идёт.
     *   Поэтому для сеанса без `ends_at` берётся окно по умолчанию, и оно
     *   вынесено в конфиг, чтобы это было ЯВНОЕ допущение, видимое в ревью, а не
     *   магическая константа внутри SQL.
     */
    'events' => [
        'session_default_duration_hours' => (int) env('EVENT_SESSION_DEFAULT_DURATION_HOURS', 3),
    ],

    /*
     * Пути, которые выполняются БЕЗ арендатора (организации).
     *
     * Читает `ResolveOrganizationContext::isSystemPath()`; такие запросы идут
     * через `OrganizationContext::withoutScope()`, то есть отсутствие
     * организации здесь — явное решение, видимое в ревью, а не догадка.
     *
     * ПОЧЕМУ ЭТОТ КЛЮЧ ВООБЩЕ ПОЯВИЛСЯ
     *   Раньше ключа не было, `config('nabilet.tenancy.system_paths', [])`
     *   возвращал пустой массив, и `isSystemPath()` всегда отвечал `false`.
     *   То есть документированный обход арендатора (он описан в шапке
     *   `ResolveOrganizationContext` — «installer, health, webhooks, sitemap»)
     *   был мёртвым кодом: он не выполнялся ни разу. Список ниже — это
     *   восстановление задокументированного поведения, а не новая политика.
     *
     * ЗАЧЕМ ЭТО НУЖНО ИМЕННО ПРОВЕРКАМ СОСТОЯНИЯ
     *   `/health` опрашивает балансировщик, у которого нет ни пользователя, ни
     *   cookie. Сейчас он работает «по счастливой случайности»: нет
     *   пользователя — нет организации. Но если в установке появится
     *   host-маппинг (`resolveFromHost`, white-label поддомены), проверка
     *   состояния внезапно оказалась бы внутри арендатора, а её результат
     *   начал бы зависеть от того, какая организация привязана к хосту.
     *   Проверка живости обязана быть независимой от арендатора по построению.
     */
    'tenancy' => [
        'system_paths' => [
            'up',
            'api/v1/ping',
            'api/v1/health',
            'install',
            'install/*',
            'webhooks/*',
            'api/v1/webhooks/*',
            'sitemap.xml',
            'api/v1/sitemap.xml',
            'robots.txt',
        ],
    ],


    'checkout' => [
        'hold_duration_minutes' => (int) env('CHECKOUT_HOLD_DURATION', 15),
        'max_items_per_order' => (int) env('CHECKOUT_MAX_ITEMS', 10),

        // Grace-окно после истечения холда: сколько ещё секунд после `expires_at`
        // платёж считается валидным, прежде чем sweeper вернёт места в продажу.
        //
        // ЭТО ЕДИНСТВЕННЫЙ ИСТОЧНИК ЭТОГО ЧИСЛА. Раньше `addMinutes(5)` /
        // `subMinutes(5)` были продублированы в пяти местах трёх модулей
        // (`HoldSweeper` ×3, `Orders\Models\SeatHold`, `Payments\Services\
        // PaymentService`). Рассинхрон между ними — это не косметика: если
        // `isHoldConvertible()` разрешает конвертацию, а `sweep()` уже освободил
        // место, вебхук оплаты падает в «hold already released» и покупатель
        // теряет оплаченное место. Меняйте значение здесь, а не по месту.
        'hold_grace_minutes' => (int) env('CHECKOUT_HOLD_GRACE_MINUTES', 5),
    ],
    
    'payment' => [
        'providers' => explode(',', env('PAYMENT_PROVIDERS', 'yookassa')),
        'webhook_secret' => env('PAYMENT_WEBHOOK_SECRET'),

        // Продакшен → ЮKassa. demo_mode=true → локальный симулятор оплаты:
                // провайдер не ходит в API, а возвращает confirmation_url на наш
                // demo-pay endpoint. Включается, когда нет реальных ключей.
                //
                // `filter_var(...)`, а не `=== 'true'`. phpdotenv приводит
                // незакавыченные `true`/`false`/`null` к PHP-типам, поэтому
                // `env('PAYMENT_DEMO_MODE')` возвращает **boolean**, и сравнение
                // со строкой не совпадало НИКОГДА: `PAYMENT_DEMO_MODE=true` в
                // `.env` давал `demo_mode => false`. Проверено:
                // `artisan config:show nabilet.payment` показывал `false` при
                // `true` в `.env`, из-за чего демо-оплата не включалась вообще.
                'demo_mode' => filter_var(env('PAYMENT_DEMO_MODE', false), FILTER_VALIDATE_BOOL),

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
