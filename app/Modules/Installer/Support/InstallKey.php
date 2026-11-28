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
        if (is_file($path)) {
            $existing = trim((string) file_get_contents($path));
            if ($existing !== '') {
                return $existing;
            }
        }

        $key = bin2hex(random_bytes(32)); // 256 бит энтропии
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог storage/ для ключа установки.');
        }
        if (file_put_contents($path, $key . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать ключ установки в storage/' . self::FILE . '.');
        }
        @chmod($path, 0600);

        return $key;
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
