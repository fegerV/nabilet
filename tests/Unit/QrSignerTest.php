<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Core\Errors\DomainRuleViolation;
use Nabilet\Core\Support\QrSigner;
use Nabilet\Tests\Support\TestCase;

/**
 * A QR code is printed, photographed, shared and screenshotted. Everything inside
 * it must be treated as public. These tests enforce ТЗ §30 literally: the payload
 * carries an opaque id, a random token and an HMAC — and nothing else.
 */
final class QrSignerTest extends TestCase
{
    private const SECRET = 'test-secret-key-that-is-long-enough-32b';

    public function testIssueAndVerifyRoundTrip(): void
    {
        $signer = new QrSigner(self::SECRET);
        $issued = $signer->issue('TICKET-1');

        $verified = $signer->verify($issued['payload']);

        $this->assertNotNull($verified);
        $this->assertSame('TICKET-1', $verified['ticketId']);
        $this->assertSame($issued['token'], $verified['token']);
    }

    public function testPayloadContainsNoPersonalOrCommercialData(): void
    {
        $signer = new QrSigner(self::SECRET);
        $payload = $signer->issue('TICKET-1')['payload'];

        // no email, no price, no phone-like digit runs beyond the random token
        $this->assertFalse(str_contains($payload, '@'), 'QR payload must not contain an email address');
        $this->assertFalse(str_contains($payload, '5000'), 'QR payload must not contain a price');
        $this->assertFalse(str_contains(strtolower($payload), 'price'));
        $this->assertFalse(str_contains(strtolower($payload), 'phone'));
        $this->assertFalse(str_contains(strtolower($payload), 'email'));
    }

    public function testRejectsTamperedTicketId(): void
    {
        $signer = new QrSigner(self::SECRET);
        $issued = $signer->issue('TICKET-1');

        $parts = explode('.', $issued['payload']);
        $parts[1] = 'TICKET-9999';           // attacker swaps the id, keeps the signature
        $forged = implode('.', $parts);

        $this->assertNull($signer->verify($forged));
    }

    public function testRejectsTamperedSignature(): void
    {
        $signer = new QrSigner(self::SECRET);
        $issued = $signer->issue('TICKET-1');

        $parts = explode('.', $issued['payload']);
        $parts[3] = str_repeat('A', strlen($parts[3]));
        $forged = implode('.', $parts);

        $this->assertNull($signer->verify($forged));
    }

    public function testRejectsPayloadSignedWithAnotherKey(): void
    {
        $real = new QrSigner(self::SECRET);
        $attacker = new QrSigner('a-completely-different-secret-32b-long');

        $forged = $attacker->issue('TICKET-1')['payload'];

        $this->assertNull($real->verify($forged));
    }

    public function testRejectsMalformedPayloads(): void
    {
        $signer = new QrSigner(self::SECRET);

        $this->assertNull($signer->verify(''));
        $this->assertNull($signer->verify('garbage'));
        $this->assertNull($signer->verify('NB1.only.three'));
        $this->assertNull($signer->verify('NB2.a.b.c'), 'unknown version prefix must be rejected');
        $this->assertNull($signer->verify('NB1..token.sig'));
        $this->assertNull($signer->verify('NB1.id..sig'));
    }

    public function testTokensAreRandomAcrossIssues(): void
    {
        $signer = new QrSigner(self::SECRET);
        $tokens = [];

        for ($i = 0; $i < 50; $i++) {
            $tokens[] = $signer->issue('TICKET-1')['token'];
        }

        $this->assertCount(50, array_unique($tokens));
    }

    public function testRejectsWeakSecret(): void
    {
        $this->assertThrows(
            DomainRuleViolation::class,
            static fn () => new QrSigner('short')
        );
    }

    /**
     * The offline checker must be able to validate locally without ever holding
     * the signing key — otherwise a stolen scanner could forge tickets.
     */
    public function testOfflineDigestIsDeterministicAndKeyed(): void
    {
        $a = new QrSigner(self::SECRET);
        $b = new QrSigner(self::SECRET);
        $c = new QrSigner('a-completely-different-secret-32b-long');

        $this->assertSame(
            $a->offlineDigest('TICKET-1', 'token-abc'),
            $b->offlineDigest('TICKET-1', 'token-abc')
        );
        $this->assertFalse(
            $a->offlineDigest('TICKET-1', 'token-abc') === $c->offlineDigest('TICKET-1', 'token-abc')
        );
    }

    public function testAcceptingAnExplicitTokenKeepsSignatureStable(): void
    {
        $signer = new QrSigner(self::SECRET);

        $first = $signer->issue('TICKET-1', 'fixed-token');
        $second = $signer->issue('TICKET-1', 'fixed-token');

        $this->assertSame($first['payload'], $second['payload']);
    }
}
