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

    /*
     * Соц-вход (модуль Auth).
     *
     * Три блока, потому что три РАЗНЫХ протокола, а не три настройки одного:
     *   Яндекс и VK — OAuth 2.0 authorization code: браузер уходит на страницу
     *     провайдера и возвращается на `GET /auth/{provider}/callback?code=&state=`;
     *   Telegram — Login Widget: браузер никуда не уходит, фронт получает
     *     подписанный `initData` и присылает его на `POST /api/v1/telegram/auth`.
     *
     * Пустой `client_id` — это выключенный провайдер, а не ошибка конфигурации:
     * `SocialProviderRegistry` отдаёт `null`, контроллер отвечает
     * `unknown_provider`/`exchange_failed`, и витрина просто не рисует кнопку.
     * Иначе пришлось бы держать список включённых провайдеров ещё и во фронте.
     */
    'yandex' => [
        'client_id' => env('YANDEX_CLIENT_ID'),
        'client_secret' => env('YANDEX_CLIENT_SECRET'),
        'redirect_uri' => env('YANDEX_REDIRECT_URI'),
    ],

    'vk' => [
        'client_id' => env('VK_CLIENT_ID'),
        'client_secret' => env('VK_CLIENT_SECRET'),
        'redirect_uri' => env('VK_REDIRECT_URI'),
    ],

    // Bot token нужен ДВУМ вещам: проверке подписи `initData` (ключ HMAC
    // выводится из него) и, если понадобится, отправке сообщений ботом.
    // Поэтому он один и тот же, а не отдельный «секрет для входа».
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        // Свежесть `auth_date` в `initData`, секунды. Сутки — компромисс:
        // ссылка из истории браузера перестаёт работать, но и перехваченный
        // `initData` не живёт вечно.
        'auth_max_age' => (int) env('TELEGRAM_AUTH_MAX_AGE', 86400),
    ],
];
