<?php

declare(strict_types=1);

namespace Nabilet\Modules\Backups\Services;

use Nabilet\Core\Support\HmacSignature;

/**
 * Зашифровать файл бэкапа перед выгрузкой во внешнее хранилище.
 *
 * Формат: `[16-байтный IV][HMAC-SHA256(32)][шифротекст]`.
 *
 *   - AES-256-CBC с ключом из конфига `nabilet.backup.encryption_key`
 *     (env: `NABILET_BACKUP_ENCRYPTION_KEY`). Ключ никогда не попадает в
 *     командную строку и не оседает в логах.
 *   - Случайный IV на каждый файл — один и тот же открытый текст даёт разные
 *     шифротексты.
 *   - HMAC-SHA256 поверх `IV || ciphertext` — детектит повреждение/подмену
 *     (шифрование без аутентификации уязвимо к битовым правкам).
 *
 * Пустой ключ считается конфигурационной ошибкой: бэкап молча уходить в
 * открытом виде не должен. Если шифрование не нужно — передайте явный флаг.
 */
final class BackupEncryptor
{
    private const CIPHER = 'aes-256-cbc';
    private const IV_LEN = 16;
    private const HMAC_LEN = 32;

    public function __construct(private readonly string $key)
    {
    }

    /**
     * Зашифровать файл: пишет рядом `<file>.enc` и возвращает его путь.
     */
    public function encryptFile(string $sourcePath): string
    {
        $this->assertKey();

        if (! is_file($sourcePath)) {
            throw new \RuntimeException("Encrypt source file not found: {$sourcePath}");
        }

        $data = file_get_contents($sourcePath);
        if ($data === false) {
            throw new \RuntimeException("Cannot read source file: {$sourcePath}");
        }

        $iv = random_bytes(self::IV_LEN);
        $ciphertext = openssl_encrypt($data, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt the backup file (openssl_encrypt).');
        }

        $hmac = HmacSignature::digest($iv . $ciphertext, $this->key, true);

        $targetPath = $sourcePath . '.enc';

        if (file_put_contents($targetPath, $iv . $hmac . $ciphertext) === false) {
            throw new \RuntimeException("Failed to write encrypted backup: {$targetPath}");
        }

        return $targetPath;
    }

    /**
     * Расшифровать файл (для восстановления). Возвращает открытый текст.
     */
    public function decrypt(string $encryptedPath): string
    {
        $this->assertKey();

        $payload = file_get_contents($encryptedPath);
        if ($payload === false) {
            throw new \RuntimeException("Cannot read encrypted file: {$encryptedPath}");
        }

        if (strlen($payload) < self::IV_LEN + self::HMAC_LEN) {
            throw new \RuntimeException('Encrypted backup file is too short (corrupt).');
        }

        $iv = substr($payload, 0, self::IV_LEN);
        $expectedHmac = substr($payload, self::IV_LEN, self::HMAC_LEN);
        $ciphertext = substr($payload, self::IV_LEN + self::HMAC_LEN);

        $actualHmac = HmacSignature::digest($iv . $ciphertext, $this->key, true);

        if (! hash_equals($expectedHmac, $actualHmac)) {
            throw new \RuntimeException('Backup integrity check failed (wrong key or corrupt file).');
        }

        $plain = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($plain === false) {
            throw new \RuntimeException('Failed to decrypt the backup file (openssl_decrypt).');
        }

        return $plain;
    }

    private function assertKey(): void
    {
        if ($this->key === '') {
            throw new \RuntimeException(
                'NABILET_BACKUP_ENCRYPTION_KEY is not configured; cannot encrypt/decrypt backups.'
            );
        }
    }
}
