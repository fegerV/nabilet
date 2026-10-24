<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\Sha256Hex;
use Nabilet\Modules\Auth\Domain\SessionToken;
use Nabilet\Modules\Checkin\Services\DeviceTokenIssuer;
use Nabilet\Tests\Support\TestCase;

/**
 * Отпечаток для `CHAR(64)`: алгоритм и длина в одном месте.
 *
 * Тест владельца фиксирует ОПУБЛИКОВАННЫЙ вектор sha256, а не сравнение с
 * самим собой: тест, который считает ожидание через `hash()`, переедет на
 * другой алгоритм вместе с кодом и останется зелёным.
 *
 * Второй смысловой блок — согласие обёрток модулей. `DeviceTokenIssuer::hash()`
 * пишет `device_token_hash`, `SessionToken::hashOf()` — `session_token_hash`;
 * оба обязаны давать один и тот же digest для одного значения, потому что
 * правило одно, а не два похожих.
 */
final class Sha256HexTest extends TestCase
{
    /** sha256 от строки "abc" — значение из RFC 6234 / FIPS 180-4. */
    private const VECTOR_ABC = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

    /** Строка из 64 символов — ширина колонок `CHAR(64)` в `migrations.sql`. */
    private const COLUMN_WIDTH = 64;

    public function testTheDigestMatchesThePublishedVector(): void
    {
        $this->assertSame(
            self::VECTOR_ABC,
            Sha256Hex::of('abc'),
            'отпечаток не совпал с опубликованным вектором sha256',
        );
    }

    public function testTheDigestIsLowercaseHexOfTheColumnWidth(): void
    {
        $digest = Sha256Hex::of('token');

        $this->assertSame(self::COLUMN_WIDTH, strlen($digest), 'длина должна совпадать с шириной CHAR(64)');
        $this->assertSame(Sha256Hex::LENGTH, strlen($digest), 'длина отпечатка должна совпадать с LENGTH владельца');
        $this->assertSame(strtolower($digest), $digest, 'hex должен быть в нижнем регистре');
    }

    public function testDifferentValuesGetDifferentDigests(): void
    {
        $this->assertNotSame(Sha256Hex::of('a'), Sha256Hex::of('b'));
    }

    /**
     * Прямая согласованность с `SessionToken`.
     *
     * Константы класса — псевдонимы владельца, и `hashOf()` считает тем же
     * `Sha256Hex::of()`. Расхождение здесь означало бы, что токен выдаётся по
     * одному алгоритму, а ищется по другому.
     */
    public function testSessionTokenAgreesWithTheOwner(): void
    {
        $this->assertSame(Sha256Hex::ALGORITHM, SessionToken::HASH_ALGORITHM);
        $this->assertSame(Sha256Hex::LENGTH, SessionToken::HASH_LENGTH);
        $this->assertSame(Sha256Hex::of('token'), SessionToken::hashOf('token'));
    }

    /**
     * Прямая согласованность с `DeviceTokenIssuer`.
     *
     * Этот отпечаток писался в трёх местах (`DeviceTokenIssuer`,
     * `AuthenticateChecker`, `OfflineBundleController`), и три копии обязаны
     * были совпадать молча. Теперь их два вызова одной функции — этот тест
     * показывает, что результат тот же.
     */
    public function testDeviceTokenIssuerAgreesWithTheOwner(): void
    {
        $this->assertSame(Sha256Hex::of('nbck_test'), DeviceTokenIssuer::hash('nbck_test'));
    }
}
