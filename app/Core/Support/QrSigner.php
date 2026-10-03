<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

use Nabilet\Core\Errors\DomainRuleViolation;

/**
 * Signs and verifies QR ticket payloads.
 *
 * Requirement (ТЗ §30): the QR must never contain email, phone, full personal
 * data, price or password. A scanner is an untrusted client held by a
 * contractor — anything readable in the QR is effectively public.
 *
 * Therefore the payload carries only an opaque ticket id, a random token, and an
 * HMAC signature. All personal and commercial data is resolved server-side after
 * the signature is verified.
 *
 * Payload layout (compact, scanner-friendly):
 *
 *     NB1.<ticketId>.<randomToken>.<signature>
 *
 * The version prefix ("NB1") allows a future key/format rotation without
 * ambiguity.
 *
 * @see docs/ARCHITECTURE.md — "QR and check-in"
 */
final class QrSigner
{
    public const VERSION = 'NB1';

    private const TOKEN_BYTES = 16;

    public function __construct(
        private readonly string $secret,
        private readonly string $keyId = 'k1',
    ) {
        if (strlen($secret) < 32) {
            throw new DomainRuleViolation(
                'QR signing secret must be at least 32 bytes. Refusing to run with a weak secret.',
                'WEAK_QR_SECRET'
            );
        }
    }

    /**
     * Issue a fresh signed payload for a ticket.
     *
     * @return array{payload: string, token: string, signature: string}
     */
    public function issue(string $ticketId, ?string $token = null): array
    {
        $token ??= bin2hex(random_bytes(self::TOKEN_BYTES));
        $signature = $this->sign($ticketId, $token);

        return [
            'payload' => sprintf('%s.%s.%s.%s', self::VERSION, $ticketId, $token, $signature),
            'token' => $token,
            'signature' => $signature,
        ];
    }

    /**
     * Verify a scanned payload.
     *
     * Uses hash_equals() for constant-time comparison so that a timing oracle
     * cannot be used to forge signatures byte by byte.
     *
     * @return array{ticketId: string, token: string}|null null when invalid
     */
    public function verify(string $payload): ?array
    {
        $parts = explode('.', $payload);

        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            return null;
        }

        [, $ticketId, $token, $signature] = $parts;

        if ($ticketId === '' || $token === '' || $signature === '') {
            return null;
        }

        $expected = $this->sign($ticketId, $token);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        return ['ticketId' => $ticketId, 'token' => $token];
    }

    /**
     * Keyed hash used for the offline checker bundle: the device receives a set
     * of (ticketId, token) hashes so it can validate locally without the secret.
     *
     * The device therefore never holds the signing key — compromising a scanner
     * does not allow forging tickets.
     */
    public function offlineDigest(string $ticketId, string $token): string
    {
        return substr(hash_hmac('sha256', self::VERSION . '|' . $ticketId . '|' . $token, $this->secret), 0, 32);
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    private function sign(string $ticketId, string $token): string
    {
        return rtrim(strtr(base64_encode(
            hash_hmac('sha256', self::VERSION . '|' . $ticketId . '|' . $token, $this->secret, true)
        ), '+/', '-_'), '=');
    }
}
