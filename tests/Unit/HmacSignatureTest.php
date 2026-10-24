<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\HmacSignature;
use Nabilet\Tests\Support\TestCase;

/**
 * Владелец подписи: алгоритм, hex-формат, постоянное сравнение.
 *
 * Ключевая проверка — ВЕКТОР ИЗ RFC 4231, а не сравнение с самим собой.
 * Тест, который считает ожидание через `hash_hmac`, ничего не доказывает:
 * если владелец переедет на sha1, тест переедет вместе с ним и останется
 * зелёным. Известный ответ фиксирует именно `sha256`.
 */
final class HmacSignatureTest extends TestCase
{
    /** RFC 4231, раздел 4.2 — ключ `key`, данные про лису. */
    private const RFC_KEY = 'key';

    private const RFC_DATA = 'The quick brown fox jumps over the lazy dog';

    private const RFC_SHA256 = 'f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8';

    public function testSignatureMatchesThePublishedSha256Vector(): void
    {
        $this->assertSame(
            self::RFC_SHA256,
            HmacSignature::sign(self::RFC_DATA, self::RFC_KEY),
            'подпись не совпала с опубликованным вектором HMAC-SHA256',
        );
    }

    public function testSignatureIsLowercaseHex(): void
    {
        $signature = HmacSignature::sign('payload', 'secret');

        $this->assertSame(64, strlen($signature), 'длина hex-подписи sha256');
        $this->assertSame(strtolower($signature), $signature, 'hex должен быть в нижнем регистре');
    }

    public function testVerifyAcceptsItsOwnSignature(): void
    {
        $signature = HmacSignature::sign('payload', 'secret');

        $this->assertTrue(HmacSignature::verify('payload', 'secret', $signature));
    }

    public function testVerifyRejectsADifferentPayload(): void
    {
        $signature = HmacSignature::sign('payload', 'secret');

        $this->assertFalse(HmacSignature::verify('payload2', 'secret', $signature));
    }

    public function testVerifyRejectsADifferentSecret(): void
    {
        $signature = HmacSignature::sign('payload', 'secret');

        $this->assertFalse(HmacSignature::verify('payload', 'other-secret', $signature));
    }

    /**
     * Пустой заголовок — это «подпись не пришла», а не «подпись верна».
     *
     * `hash_equals('', '')` истинно, поэтому без явной проверки запрос без
     * заголовка проходил бы на любом секрете, который тоже пуст.
     */
    public function testVerifyRejectsAnEmptySignature(): void
    {
        $this->assertFalse(HmacSignature::verify('payload', 'secret', ''));
    }

    /**
     * Регистр hex не нормализуется осознанно.
     *
     * Провайдеры присылают нижний регистр, `hash_hmac` возвращает нижний.
     * Принимать ещё и верхний — расширять множество допустимых подписей ради
     * удобства, которого никто не просил.
     */
    public function testVerifyIsCaseSensitive(): void
    {
        $signature = HmacSignature::sign('payload', 'secret');

        $this->assertFalse(HmacSignature::verify('payload', 'secret', strtoupper($signature)));
    }

    public function testRawDigestIsTheBinaryFormOfTheHexSignature(): void
    {
        $raw = HmacSignature::digest(self::RFC_DATA, self::RFC_KEY, raw: true);

        $this->assertSame(32, strlen($raw), 'sha256 — 32 байта');
        $this->assertSame(self::RFC_SHA256, bin2hex($raw));
    }

    /**
     * Пустой секрет — отказ, а не «подпись ничем».
     *
     * HMAC с пустым ключом вычислит кто угодно: это не «секрет не настроен»,
     * это «секрет общеизвестен». Пока проверка была только на `null`, пустая
     * строка в конфиге давала именно это — проверка шла, но проверять было
     * нечего.
     */
    public function testSigningWithAnEmptySecretIsRefused(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => HmacSignature::sign('payload', ''),
            'подпись пустым ключом должна быть отказана',
        );
    }

    public function testVerifyingWithAnEmptySecretIsRefused(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            fn () => HmacSignature::verify('payload', '', 'f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8'),
            'проверка пустым ключом должна быть отказана',
        );
    }

    public function testTheAlgorithmIsNamedInOnePlace(): void
    {
        $this->assertSame('sha256', HmacSignature::ALGORITHM);
    }
}
