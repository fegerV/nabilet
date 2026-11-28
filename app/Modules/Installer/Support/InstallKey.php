<?php

declare(strict_types=1);

namespace Nabilet\Modules\Installer\Support;

/**
 * Одноразовый ключ первоначальной установки.
 *
 * Ключ НЕ приезжает в архиве и НЕ отдаётся через /install: любой посетитель
 * видит только имя файла и инструкцию. Владелец читает его через файловый
 * менеджер хостинга или SSH (файл лежит вне DocumentRoot) и вводит в визард.
 * После успешной установки файл удаляется, а маршруты мастера перестают
 * регистрироваться — повторная установка требует отдельной процедуры.
 */
final class InstallKey
{
    public const FILE = 'install.key';

    public const HEADER = 'X-Install-Key';

    public static function path(): string
    {
        return storage_path(self::FILE);
    }

    /** Создать ключ при первом запросе и вернуть его (только серверная сторона). */
    public static function ensure(): string
    {
        $path = self::path();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог storage/ для ключа установки.');
        }

        // 'x' создаёт файл только если его ещё нет: два параллельных запроса
        // не могут перезаписать ключ друг друга.
        $handle = @fopen($path, 'x');
        if ($handle !== false) {
            $key = bin2hex(random_bytes(32)); // 256 бит энтропии
            fwrite($handle, $key . "\n");
            fflush($handle);
            flock($handle, LOCK_EX | LOCK_NB);
            fclose($handle);
            @chmod($path, 0600);

            return $key;
        }

        // Файл уже создан другим запросом — читаем его с общей блокировкой.
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось прочитать ключ установки.');
        }
        flock($handle, LOCK_SH);
        $existing = trim(stream_get_contents($handle));
        flock($handle, LOCK_UN);
        fclose($handle);
        if ($existing === '') {
            throw new \RuntimeException('Ключ установки пуст — удалите storage/install.key вручную.');
        }

        return $existing;
    }

    public static function verify(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }
        $expected = self::ensure();

        return hash_equals($expected, trim($candidate));
    }

    public static function forget(): void
    {
        @unlink(self::path());
    }

    /** @return array{file: string, hint: string} */
    public static function instructions(): array
    {
        return [
            'file' => 'storage/' . self::FILE,
            'hint' => 'Откройте файл storage/install.key через файловый менеджер хостинга '
                . 'или SSH (cat storage/install.key) и введите его содержимое в мастере. '
                . 'Ключ создаётся автоматически при первом открытии /install и удаляется после установки.',
        ];
    }
}
