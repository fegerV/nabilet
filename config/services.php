<?php

declare(strict_types=1);

/*
 * Внешние сервисы.
 *
 * Файла `config/services.php` в проекте не было вовсе, поэтому
 * `config('services.yandex_disk.oauth_token')` ВСЕГДА возвращал null:
 * `YandexDiskBackupService` не мог выгрузить ни одной резервной копии — то есть
 * контур бэкапов был мёртв целиком, а не только не подключён к расписанию.
 */

return [
    // Яндекс.Диск — внешнее хранилище резервных копий (ТЗ §72).
    // Токен выдаётся приложению в Яндекс OAuth; бэкап без него создать можно,
    // но выгрузить за пределы хостинга — нет.
    'yandex_disk' => [
        'oauth_token' => env('YANDEX_DISK_OAUTH_TOKEN'),
        'backups_path' => env('YANDEX_DISK_BACKUPS_PATH', '/backups'),
    ],
];
