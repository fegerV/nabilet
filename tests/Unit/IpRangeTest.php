<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Support\IpRange;
use Nabilet\Tests\Support\TestCase;

/**
 * `IpRange` — записи IP-allowlist вебхуков.
 *
 * Дефект, ради которого класс появился: `WebhookAuthenticator` сравнивал адрес
 * через `in_array($ip, $allowlist, true)`, а `.env.example` и сообщение в логе
 * обещали CIDR. Запись `185.32.0.0/24` не совпадала ни с одним адресом — то
 * есть настроенная защита отвергала все уведомления ЮKassa.
 *
 * Границы проверяются по краям сетки (последний адрес внутри и первый снаружи):
 * ошибка на единицу в маске выглядит именно так — «почти работает».
 */
final class IpRangeTest extends TestCase
{
    // ── точный адрес ────────────────────────────────────────────────────────

    public function testAnExactIPv4AddressMatchesOnlyItself(): void
    {
        $this->assertTrue(IpRange::contains('203.0.113.10', '203.0.113.10'));
        $this->assertFalse(IpRange::contains('203.0.113.11', '203.0.113.10'));
        $this->assertFalse(IpRange::contains('203.0.113.1', '203.0.113.10'));
    }

    public function testAnExactIPv6AddressMatchesOnlyItself(): void
    {
        $this->assertTrue(IpRange::contains('2001:db8::1', '2001:db8::1'));
        $this->assertFalse(IpRange::contains('2001:db8::2', '2001:db8::1'));
    }

    public function testSurroundingWhitespaceIsIgnored(): void
    {
        // `config/nabilet.php` режет переменную по запятой без `trim`, поэтому
        // `a, b` даёт запись с ведущим пробелом.
        $this->assertTrue(IpRange::contains(' 203.0.113.10 ', ' 203.0.113.10 '));
        $this->assertTrue(IpRange::contains('203.0.113.10', " 203.0.113.0/24\n"));
    }

    // ── IPv4 CIDR: края сетки ───────────────────────────────────────────────

    public function testA27NetworkIncludesItsLastAddressAndExcludesTheNextOne(): void
    {
        // 203.0.113.0/27 — адреса .0 … .31.
        $this->assertTrue(IpRange::contains('203.0.113.0', '203.0.113.0/27'));
        $this->assertTrue(IpRange::contains('203.0.113.31', '203.0.113.0/27'));
        $this->assertFalse(IpRange::contains('203.0.113.32', '203.0.113.0/27'));
        $this->assertFalse(IpRange::contains('203.0.112.255', '203.0.113.0/27'));
    }

    public function testA25NetworkIncludesItsLastAddressAndExcludesTheNextOne(): void
    {
        // 203.0.113.0/25 — адреса .0 … .127. Маска не выровнена по байту:
        // ровно здесь побитовая операция отличается от сравнения префикса.
        $this->assertTrue(IpRange::contains('203.0.113.0', '203.0.113.0/25'));
        $this->assertTrue(IpRange::contains('203.0.113.127', '203.0.113.0/25'));
        $this->assertFalse(IpRange::contains('203.0.113.128', '203.0.113.0/25'));
    }

    public function testASlashZeroIPv4NetworkMatchesEveryIPv4Address(): void
    {
        $this->assertTrue(IpRange::contains('0.0.0.0', '0.0.0.0/0'));
        $this->assertTrue(IpRange::contains('8.8.8.8', '0.0.0.0/0'));
        $this->assertTrue(IpRange::contains('255.255.255.255', '0.0.0.0/0'));
        // И не совпадает с IPv6-адресом: семьи не смешиваются.
        $this->assertFalse(IpRange::contains('2001:db8::1', '0.0.0.0/0'));
    }

    public function testA32NetworkIsAnExactAddress(): void
    {
        $this->assertTrue(IpRange::contains('203.0.113.10', '203.0.113.10/32'));
        $this->assertFalse(IpRange::contains('203.0.113.11', '203.0.113.10/32'));
    }

    // ── IPv6 CIDR ───────────────────────────────────────────────────────────

    public function testAnIPv6PrefixIncludesItsLastAddressAndExcludesTheNextOne(): void
    {
        // 2001:db8::/32 — вторая половина второго 16-битного слова.
        $this->assertTrue(IpRange::contains('2001:db8::1', '2001:db8::/32'));
        $this->assertTrue(IpRange::contains('2001:db8:ffff:ffff:ffff:ffff:ffff:ffff', '2001:db8::/32'));
        $this->assertFalse(IpRange::contains('2001:db9::1', '2001:db8::/32'));
    }

    public function testAnIPv6SlashOneHundredAndTwentySevenNetwork(): void
    {
        // 2001:db8::/127 — ровно два адреса; маска не выровнена по байту.
        $this->assertTrue(IpRange::contains('2001:db8::', '2001:db8::/127'));
        $this->assertTrue(IpRange::contains('2001:db8::1', '2001:db8::/127'));
        $this->assertFalse(IpRange::contains('2001:db8::2', '2001:db8::/127'));
    }

    public function testASlashZeroIPv6NetworkMatchesEveryIPv6Address(): void
    {
        $this->assertTrue(IpRange::contains('2001:db8::1', '::/0'));
        $this->assertTrue(IpRange::contains('::1', '::/0'));
        $this->assertFalse(IpRange::contains('203.0.113.10', '::/0'));
    }

    public function testAnIPv6ZoneIdIsIgnored(): void
    {
        // Ссылочный адрес приходит с zone id, которого `inet_pton` не понимает.
        $this->assertTrue(IpRange::contains('fe80::1%eth0', 'fe80::/10'));
        $this->assertTrue(IpRange::contains('fe80::1', 'fe80::1%eth0'));
    }

    // ── IPv4 против IPv4-mapped IPv6 ────────────────────────────────────────

    public function testAnIPv4MappedAddressMatchesTheIPv4Network(): void
    {
        $this->assertTrue(IpRange::contains('::ffff:203.0.113.10', '203.0.113.0/24'));
        $this->assertTrue(IpRange::contains('203.0.113.10', '::ffff:203.0.113.0/120'));
    }

    public function testAnUnmappedIPv6AddressDoesNotMatchAnIPv4Network(): void
    {
        $this->assertFalse(IpRange::contains('2001:db8::1', '203.0.113.0/24'));
    }

    // ── мусор: fail-closed ──────────────────────────────────────────────────

    public function testMalformedRangesNeverMatch(): void
    {
        $this->assertFalse(IpRange::contains('203.0.113.10', ''));
        $this->assertFalse(IpRange::contains('203.0.113.10', '   '));
        $this->assertFalse(IpRange::contains('203.0.113.10', '*'));
        $this->assertFalse(IpRange::contains('203.0.113.10', '203.0.113.0/33'));
        $this->assertFalse(IpRange::contains('2001:db8::1', '2001:db8::/129'));
        $this->assertFalse(IpRange::contains('203.0.113.10', '203.0.113.0/-1'));
        $this->assertFalse(IpRange::contains('203.0.113.10', '203.0.113.0/'));
        $this->assertFalse(IpRange::contains('203.0.113.10', '203.0.113.0/24/8'));
        $this->assertFalse(IpRange::contains('203.0.113.10', '203.0.113.0 /24'));
        $this->assertFalse(IpRange::contains('203.0.113.10', '1.2.3.4.5'));
        $this->assertFalse(IpRange::contains('203.0.113.10', 'nonsense'));
        // Zone id у IPv4 — мусор, а не повод отбросить «%» и принять адрес.
        $this->assertFalse(IpRange::contains('203.0.113.10', '203.0.113.10%eth0'));
    }

    public function testAMalformedAddressNeverMatches(): void
    {
        $this->assertFalse(IpRange::contains('', '203.0.113.0/24'));
        $this->assertFalse(IpRange::contains('nonsense', '0.0.0.0/0'));
        $this->assertFalse(IpRange::contains('999.999.999.999', '0.0.0.0/0'));
        $this->assertFalse(IpRange::contains('203.0.113.10; DROP TABLE users', '203.0.113.0/24'));
    }

    // ── isValid: тот же разбор, что и у contains ────────────────────────────

    public function testIsValidAcceptsAddressesAndCidr(): void
    {
        $this->assertTrue(IpRange::isValid('203.0.113.10'));
        $this->assertTrue(IpRange::isValid('203.0.113.0/24'));
        $this->assertTrue(IpRange::isValid('0.0.0.0/0'));
        $this->assertTrue(IpRange::isValid('2001:db8::1'));
        $this->assertTrue(IpRange::isValid('2001:db8::/32'));
        $this->assertTrue(IpRange::isValid('::/0'));
        $this->assertTrue(IpRange::isValid(' 203.0.113.0/24 '));
    }

    public function testIsValidRejectsWildcardsAndMalformedEntries(): void
    {
        $this->assertFalse(IpRange::isValid('*'));
        $this->assertFalse(IpRange::isValid(''));
        $this->assertFalse(IpRange::isValid('203.0.113.0/33'));
        $this->assertFalse(IpRange::isValid('2001:db8::/129'));
        $this->assertFalse(IpRange::isValid('203.0.113.0/-1'));
        $this->assertFalse(IpRange::isValid('203.0.113.0/abc'));
        $this->assertFalse(IpRange::isValid('203.0.113.0 /24'));
        $this->assertFalse(IpRange::isValid('localhost'));
    }
}
