<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\PackedIp;
use Nabilet\Tests\Support\TestCase;

/**
 * Packed IP addresses and the SQL that stores them.
 *
 * The column is `VARBINARY(16)` per the ТЗ, and the packed bytes are NUL-heavy:
 * `inet_pton('127.0.0.1')` is `7f 00 00 01`, `10.0.0.1` is `0a 00 00 01`. One NUL
 * mistaken for a string terminator would leave a single byte in the audit trail
 * instead of four, with nothing to notice. `tools/repro-binary-ip.php` measures
 * what a live server actually stores for each write form.
 *
 * Nothing here needs a database: the value is asserted as it is *expressed*, so
 * the expression is what is checked.
 */
final class PackedIpTest extends TestCase
{
    // ── packing ─────────────────────────────────────────────────────────────

    public function testAnIPv4AddressPacksToFourBytes(): void
    {
        $packed = PackedIp::pack('127.0.0.1');

        $this->assertSame("\x7f\x00\x00\x01", $packed);
        $this->assertSame(4, strlen((string) $packed));
    }

    public function testNulHeavyAddressesStillPackToFourBytes(): void
    {
        // First byte non-zero, every byte after it zero: exactly the shape a
        // string-terminating layer would shorten to a single byte. The full four
        // bytes are asserted so a regression cannot hide behind a length check.
        $this->assertSame(4, strlen((string) PackedIp::pack('127.0.0.1')));
        $this->assertSame(4, strlen((string) PackedIp::pack('10.0.0.1')));
        $this->assertSame("\x0a\x00\x00\x01", PackedIp::pack('10.0.0.1'));
    }

    public function testAnIPv6AddressPacksToSixteenBytes(): void
    {
        $packed = PackedIp::pack('2001:db8::1');

        $this->assertSame(16, strlen((string) $packed));
        $this->assertSame(16, PackedIp::MAX_BYTES);
    }

    public function testUnusableInputPacksToNull(): void
    {
        // Fail closed: null says "not recorded", which is honest. Storing the bytes
        // of the literal string "unknown" would look like a real address.
        $this->assertNull(PackedIp::pack(null));
        $this->assertNull(PackedIp::pack(''));
        $this->assertNull(PackedIp::pack('   '));
        $this->assertNull(PackedIp::pack('not-an-ip'));
        $this->assertNull(PackedIp::pack('999.999.999.999'));
        $this->assertNull(PackedIp::pack('127.0.0.1; DROP TABLE users'));
    }

    public function testSurroundingWhitespaceIsTolerated(): void
    {
        $this->assertSame(PackedIp::pack('127.0.0.1'), PackedIp::pack('  127.0.0.1  '));
    }

    // ── unpacking ───────────────────────────────────────────────────────────

    public function testPackedBytesUnpackToTheOriginalAddress(): void
    {
        $this->assertSame('127.0.0.1', PackedIp::unpack("\x7f\x00\x00\x01"));
        $this->assertSame('2001:db8::1', PackedIp::unpack((string) PackedIp::pack('2001:db8::1')));
    }

    public function testUnpackingRejectsNonAddresses(): void
    {
        $this->assertNull(PackedIp::unpack(null));
        $this->assertNull(PackedIp::unpack(''));
        $this->assertNull(PackedIp::unpack('too-long-to-be-an-address'));
    }

    // ── the SQL expression ──────────────────────────────────────────────────

    public function testMySqlGetsUnhex(): void
    {
        // MySQL 8.4 is the sole target: the column is VARBINARY(16) and UNHEX() is
        // the spelling that writes those bytes exactly.
        $this->assertSame("UNHEX('7f000001')", PackedIp::toSqlLiteral('127.0.0.1'));
    }

    public function testAnIPv6LiteralIsThirtyTwoHexCharacters(): void
    {
        $literal = (string) PackedIp::toSqlLiteral('2001:db8::1');

        $this->assertSame("UNHEX('20010db8000000000000000000000001')", $literal);
    }

    public function testNoExpressionIsProducedForNothingToStore(): void
    {
        $this->assertNull(PackedIp::toSqlLiteral(null));
        $this->assertNull(PackedIp::toSqlLiteral(''));
        $this->assertNull(PackedIp::toSqlLiteral('not-an-ip'));
    }

    public function testHostileInputProducesNoLiteralAtAll(): void
    {
        // The literal is inlined into a statement rather than bound, so the only
        // thing keeping it safe is that it is `bin2hex()` output. `inet_pton()` is
        // strict, so a payload cannot become a literal in the first place — the
        // guarantee is "no expression", which is stronger than "escaped
        // expression", and it is asserted rather than assumed.
        $this->assertNull(PackedIp::toSqlLiteral("127.0.0.1'); DROP TABLE users; --"));
        $this->assertNull(PackedIp::toSqlLiteral("1' OR '1'='1"));
        $this->assertNull(PackedIp::toSqlLiteral('\x7f000001'));
    }

    public function testAValidAddressAlwaysProducesAWellFormedLiteral(): void
    {
        $this->assertSame(
            1,
            preg_match("/^UNHEX\('[0-9a-f]{8}'\)$/", (string) PackedIp::toSqlLiteral('127.0.0.1'))
        );
        $this->assertSame(
            1,
            preg_match("/^UNHEX\('[0-9a-f]{32}'\)$/", (string) PackedIp::toSqlLiteral('2001:db8::1'))
        );
    }
}
