<?php

declare(strict_types=1);

/*
 * One-click обновление системы (docs/ONE_CLICK_UPDATE.md).
 *
 * Всё, что решает «можно ли обновляться и откуда», лежит здесь, а не literals
 * по коду: лента, ключ проверки подписи, лимиты и политика бэкапа. Значения
 * осознанно консервативны: обновление кассовой системы — привилегированная
 * операция, и её параметры должны быть видимы в `artisan config:show updates`.
 */
return [
    /*
     * HTTPS-лента с манифестом релиза. Пусто — лента выключена, доступна только
     * ручная загрузка архива через админку.
     */
    'feed_url' => rtrim((string) env('NABILET_UPDATE_FEED_URL', ''), '/'),

    'channel' => (string) env('NABILET_UPDATE_CHANNEL', 'stable'),

    /*
     * Публичный ключ проверки подписи манифеста. В .env хранится base64 DER-ключа
     * или base64 PEM (допускается и готовый PEM с \n). Пустой ключ означает,
     * что подписанные лентой релизы НЕ принимаются — принимается только ручная
     * загрузка с проверкой sha256 из .sha256-файла.
     */
    'public_key' => (string) env('NABILET_UPDATE_PUBLIC_KEY', ''),

    'timeout_seconds' => max(5, min(60, (int) env('NABILET_UPDATE_TIMEOUT', 15))),

    'max_bytes' => max(1024, (int) env('NABILET_UPDATE_MAX_BYTES', 64 * 1024 * 1024)),

    'max_unpacked_bytes' => max(1024, (int) env('NABILET_UPDATE_MAX_UNPACKED', 512 * 1024 * 1024)),

    'max_entries' => max(100, (int) env('NABILET_UPDATE_MAX_ENTRIES', 20000)),

    'check_cache_key' => (string) env('NABILET_UPDATE_CHECK_CACHE_KEY', 'updates:feed:check'),

    'cache_ttl_seconds' => max(0, (int) env('NABILET_UPDATE_CHECK_CACHE_TTL', 900)),

    'keep_releases' => max(0, min(5, (int) env('NABILET_UPDATE_KEEP_RELEASES', 2))),

    /*
     * Автооткат кода, если после замены файлов обновление провалилось и сайт
     * остался в режиме обслуживания. Админка в этот момент недоступна, поэтому
     * ждать «кто-то нажмёт Откатить» нельзя. 0 отключает автооткат (для стендов,
     * где оператор сознательно чинит миграции вручную).
     */
    'auto_rollback_after_minutes' => max(0, (int) env('NABILET_UPDATE_AUTO_ROLLBACK_MINUTES', 10)),

    /*
     * Возраст блокировки шага, после которого её можно считать брошенной.
     * Должен быть больше типового времени swaps/миграций, иначе живая
     * операция потеряет лок посредине работы.
     */
    'lock_stale_minutes' => max(5, (int) env('NABILET_UPDATE_LOCK_STALE_MINUTES', 15)),

    /*
     * Максимальный возраст полной резервной копии. 26 часов признаны
     * неприемлемо долгим окном: за это время могли пройти новые продажи.
     */
    'backup_max_age_hours' => max(1, min(72, (int) env('NABILET_UPDATE_BACKUP_MAX_AGE_HOURS', 6))),

    /*
     * Без свежей full-копии обновление не стартует. Это не удобство, а единственный
     * способ вернуть билеты и деньги после неудачной миграции. Выключать можно
     * только на стенде, где нет данных клиентов.
     */
    'require_backup' => filter_var(env('NABILET_UPDATE_REQUIRE_BACKUP', true), FILTER_VALIDATE_BOOL),

    /*
     * Если Диск не подключён, делать полный бэкап локально в storage/app/backups.
     * Копия остаётся на том же сервере — это слабее 3-2-1, но лучше, чем
     * блокировать обновление вообще. false возвращает старое поведение.
     */
    'allow_local_backup' => filter_var(env('NABILET_UPDATE_ALLOW_LOCAL_BACKUP', true), FILTER_VALIDATE_BOOL),

    /*
     * Каталоги, которые НЕ перезаписываются релизом. Список — единственный
     * источник правды для applier и preflight; добавление нового пользовательского
     * каталога делается здесь, а не в коде подмены.
     */
    'protected_paths' => [
        '.env',
        'storage',
        'bootstrap/cache',
        'public_html/.htaccess',
        'public_html/.user.ini',
        'public_html/favicon.svg',
        'public_html/robots.txt',
        'public_html/images',
        'public_html/storage',
    ],
];
