<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\PackedIp;
use Nabilet\Tests\Support\TestCase;

/**
 * Packed IP addresses and the SQL that stores them.
 *
 * The bug this exists to prevent was reproduced in `tools/repro-binary-ip.php`:
 * binding `inet_pton('127.0.0.1')` as an ordinary string stored **one byte** (`7f`)
 * instead of four (`7f000001`) on PostgreSQL, with no error. The column is
 * `VARBINARY(16)` per the ТЗ, so a 10.x.x.x address — mostly zero bytes — would
 * have been stored as a single byte in the audit trail.
 *
 * Nothing here needs a database: the failure was in how the value is *expressed*,
 * so the expression is what is asserted.
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

    public function testTheTruncationCaseIsFourBytesNotOne(): void
    {
        // The exact address from the repro: its first byte is non-zero and every
        // byte after it is zero, so a string bind kept only `7f`.
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

    public function testThePostgresHexOutputFormIsRecognised(): void
    {
        // PDO_PGSQL returns a `bytea` column in this form, not as raw bytes. A
        // reader that assumed raw bytes would silently misread every value.
        $this->assertSame('127.0.0.1', PackedIp::unpackPostgresHex('\x7f000001'));
        $this->assertSame('10.0.0.1', PackedIp::unpackPostgresHex('\x0a000001'));
    }

    public function testThePostgresFormIsNotClaimedForOtherShapes(): void
    {
        $this->assertNull(PackedIp::unpackPostgresHex(null));
        $this->assertNull(PackedIp::unpackPostgresHex("\x7f\x00\x00\x01"));  // raw MySQL bytes
        $this->assertNull(PackedIp::unpackPostgresHex('\\x'));
        $this->assertNull(PackedIp::unpackPostgresHex('\x7f0'));            // odd length
        $this->assertNull(PackedIp::unpackPostgresHex('\xzz'));
    }

    // ── the SQL expression ──────────────────────────────────────────────────

    public function testPostgresGetsDecodeOnTheHex(): void
    {
        $this->assertSame("decode('7f000001', 'hex')", PackedIp::toSqlLiteral('pgsql', '127.0.0.1'));
    }

    public function testMySqlGetsUnhex(): void
    {
        // The ТЗ targets MySQL, where the column is VARBINARY and UNHEX() is the
        // spelling. PostgreSQL has no UNHEX() and MySQL has no decode().
        $this->assertSame("UNHEX('7f000001')", PackedIp::toSqlLiteral('mysql', '127.0.0.1'));
    }

    public function testAnIPv6LiteralIsThirtyTwoHexCharacters(): void
    {
        $literal = (string) PackedIp::toSqlLiteral('pgsql', '2001:db8::1');

        $this->assertSame("decode('20010db8000000000000000000000001', 'hex')", $literal);
    }

    public function testNoExpressionIsProducedForNothingToStore(): void
    {
        $this->assertNull(PackedIp::toSqlLiteral('pgsql', null));
        $this->assertNull(PackedIp::toSqlLiteral('mysql', ''));
        $this->assertNull(PackedIp::toSqlLiteral('pgsql', 'not-an-ip'));
    }

    public function testHostileInputProducesNoLiteralAtAll(): void
    {
        // The literal is inlined into a statement rather than bound, so the only
        // thing keeping it safe is that it is `bin2hex()` output. `inet_pton()` is
        // strict, so a payload cannot become a literal in the first place — the
        // guarantee is "no expression", which is stronger than "escaped
        // expression", and it is asserted rather than assumed.
        $this->assertNull(PackedIp::toSqlLiteral('pgsql', "127.0.0.1'); DROP TABLE users; --"));
        $this->assertNull(PackedIp::toSqlLiteral('pgsql', "1' OR '1'='1"));
        $this->assertNull(PackedIp::toSqlLiteral('pgsql', '\x7f000001'));
    }

    public function testAValidAddressAlwaysProducesAWellFormedLiteral(): void
    {
        $this->assertSame(
            1,
            preg_match("/^decode\('[0-9a-f]{8}', 'hex'\)$/", (string) PackedIp::toSqlLiteral('pgsql', '127.0.0.1'))
        );
        $this->assertSame(
            1,
            preg_match("/^UNHEX\('[0-9a-f]{32}'\)$/", (string) PackedIp::toSqlLiteral('mysql', '2001:db8::1'))
        );
    }
}
