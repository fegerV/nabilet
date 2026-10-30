<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Backups\Services\BackupEncryptor;
use Nabilet\Tests\Support\TestCase;

/**
 * Шифрование бэкапов (AES-256-CBC + HMAC).
 *
 * Проверяет:
 *   - круговая: зашифрованный файл расшифровывается обратно в исходный текст;
 *   - шифротекст не содержит открытый текст (реальный уровень защиты);
 *   - повреждение (битовая правка) детектится HMAC, а не молча расшифровывается;
 *   - пустой ключ — конфигурационная ошибка, а не тихий открытый вывод.
 */
final class BackupEncryptorTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function test_round_trip_restores_the_plaintext(): void
    {
        $encryptor = new BackupEncryptor(self::KEY);
        $plain = 'START TRANSACTION; INSERT INTO users ... COMMIT;';

        $tmp = tempnam(sys_get_temp_dir(), 'bkp_');
        file_put_contents($tmp, $plain);

        $enc = $encryptor->encryptFile($tmp);
        $this->assertSame(true, is_file($enc), 'зашифрованный файл должен существовать');

        $this->assertSame($plain, $encryptor->decrypt($enc));

        @unlink($enc);
        @unlink($tmp);

        @unlink($enc);
    }

    public function test_ciphertext_does_not_contain_plaintext(): void
    {
        $encryptor = new BackupEncryptor(self::KEY);
        $plain = 'SUPER-SECRET-DB-CONTENT';

        $tmp = tempnam(sys_get_temp_dir(), 'bkp_');
        file_put_contents($tmp, $plain);

        $enc = $encryptor->encryptFile($tmp);
        $cipher = file_get_contents($enc);

        $this->assertSame(true, is_string($cipher));
        $this->assertSame(false, str_contains((string) $cipher, $plain), 'открытый текст засветился в шифротексте');

        @unlink($enc);
        @unlink($tmp);
    }

    public function test_tampering_is_detected_by_hmac(): void
    {
        $encryptor = new BackupEncryptor(self::KEY);

        $tmp = tempnam(sys_get_temp_dir(), 'bkp_');
        file_put_contents($tmp, 'plain');
        $enc = $encryptor->encryptFile($tmp);

        // Портим один байт в хвосте шифротекста (после IV+HMAC).
        $contents = file_get_contents($enc);
        $pos = strlen($contents) - 1;
        $contents[$pos] = $contents[$pos] === 'A' ? 'B' : 'A';
        file_put_contents($enc, $contents);

        try {
            $encryptor->decrypt($enc);
            $this->fail('Повреждённый шифротекст не должен расшифровываться');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('integrity', $e->getMessage());
        }

        @unlink($enc);
    }

    public function test_empty_key_is_rejected(): void
    {
        $encryptor = new BackupEncryptor('');

        $tmp = tempnam(sys_get_temp_dir(), 'bkp_');
        file_put_contents($tmp, 'plain');

        try {
            $encryptor->encryptFile($tmp);
            $this->fail('Пустой ключ должен быть ошибкой');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ENCRYPTION_KEY', $e->getMessage());
        }

        @unlink($tmp);
    }
}
