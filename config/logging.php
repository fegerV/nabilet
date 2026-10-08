<?php

declare(strict_types=1);

use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Nabilet\Core\Logging\RedactSensitiveData;

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily'],
            'ignore_exceptions' => false,
        ],

        /*
         * ВНИМАНИЕ: драйвер здесь `monolog`, а не `daily`, и это не стилевое
         * предпочтение.
         *
         * У драйвера `daily` (и `single`) ключ `processors` НЕ ЧИТАЕТСЯ ВООБЩЕ:
         * `LogManager::createRotatingDriver()` собирает Monolog с жёстко
         * заданным списком `[new PsrLogMessageProcessor()]` и `$config['processors']`
         * не смотрит. Только `createMonologDriver()` (драйвер `monolog`)
         * прокидывает процессоры в логгер.
         *
         * Это было проверено на живой установке, а не выведено из документации:
         * конфигурация с `'driver' => 'daily'` и `'processors' => [...]`
         * выглядела правильной, тест на содержимое конфига её подтверждал, а в
         * `storage/logs/laravel-*.log` по-прежнему лежали адрес и пароль
         * покупателя в открытом виде — включая аргументы в трассировке
         * исключения. То есть маскирование не работало и при этом выглядело
         * включённым. Подробности — в `Nabilet\Core\Logging\RedactSensitiveData`.
         *
         * `maxFiles` = 14 повторяет прежний `days`, а `PsrLogMessageProcessor`
         * повторяет прежний `replace_placeholders: true`.
         *
         * ПОРЯДОК ПРОЦЕССОРОВ ВАЖЕН: `PsrLogMessageProcessor` идёт ПЕРВЫМ,
         * потому что он подставляет значения из `$context` в текст сообщения
         * по плейсхолдерам `{key}`. Если поставить маскирование раньше,
         * подстановка вернёт персональные данные в уже очищенное сообщение.
         */
        'daily' => [
            'driver' => 'monolog',
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/laravel.log'),
                'maxFiles' => 14,
            ],
            'level' => env('LOG_LEVEL', 'warning'),
            'processors' => [
                PsrLogMessageProcessor::class,
                RedactSensitiveData::class,
            ],
        ],

        /*
         * Security-relevant events (rejected check-ins, permission denials,
         * tenant-context violations) go to their own file so they can be shipped
         * to a SIEM without parsing the application log.
         *
         * Маскирование обязательно и здесь, и это не дублирование: канал
         * хранится 90 дней вместо 14 и уезжает во внешнюю систему, то есть
         * персональные данные жили бы в нём в шесть раз дольше и в месте, где
         * их не удалить по запросу субъекта.
         */
        'security' => [
            'driver' => 'monolog',
            'handler' => RotatingFileHandler::class,
            'handler_with' => [
                'filename' => storage_path('logs/security.log'),
                'maxFiles' => 90,
            ],
            'level' => 'warning',
            'processors' => [
                PsrLogMessageProcessor::class,
                RedactSensitiveData::class,
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'warning'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
