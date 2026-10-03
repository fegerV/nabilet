<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Domain;

/**
 * A bearer token and the hash that is stored for it (ТЗ §5, §6).
 *
 * `user_sessions.session_token_hash` is `CHAR(64) NOT NULL` with
 * `UNIQUE uq_user_sessions_token` — read from the live schema, not assumed. What
 * is stored is therefore `sha256` of the token in hex, which is exactly 64
 * characters. The token itself never reaches the database, so a dump of
 * `user_sessions` is not a set of usable credentials.
 *
 * WHY 64 BYTES AND NOT 32
 *   Both are unguessable, so this is not about entropy. A 64-byte token is 128 hex
 *   characters while the hash it maps to is 64, which means a token and a hash
 *   cannot be mistaken for one another by a person reading a log line, a support
 *   ticket or a paste into a bug report. A 32-byte token would be 64 characters —
 *   the same shape as the stored hash — and confusing the two would hand out the
 *   stored value as if it were a credential.
 *
 * NO PEPPER AND NO KDF, ON PURPOSE
 *   The input is 512 bits of `random_bytes`, not a password. There is no
 *   dictionary to slow an attacker down, and a per-installation secret would make
 *   a restored backup unusable while adding nothing against a brute-force search
 *   of a 512-bit space.
 *
 * Framework-free, so it is testable without `vendor/` — the security-relevant
 * arithmetic is here rather than inside the service that talks to the database.
 */
final class SessionToken
{
    /** Entropy of the plaintext token, in bytes. 64 bytes = 512 bits. */
    public const BYTES = 64;

    /** `session_token_hash` is CHAR(64), so the digest must be sha256 in hex. */
    public const HASH_ALGORITHM = 'sha256';

    /** sha256 in hex is 64 characters — the width the column declares. */
    public const HASH_LENGTH = 64;

    private function __construct(
        public readonly string $plain,
        public readonly string $hash,
    ) {
    }

    /** A fresh token, together with the hash to store for it. */
    public static function generate(): self
    {
        return self::fromPlain(bin2hex(random_bytes(self::BYTES)));
    }

    /** Reconstruct a token from what a client sent, and derive its hash. */
    public static function fromPlain(string $plain): self
    {
        return new self($plain, self::hashOf($plain));
    }

    public static function hashOf(string $plain): string
    {
        return hash(self::HASH_ALGORITHM, $plain);
    }

    /**
     * Is this even the right shape to be a token we issued?
     *
     * A cheap reject before touching the database. It is not the security check —
     * the hash lookup is — but it keeps a malformed `Authorization` header from
     * becoming a query, and it keeps an operator from pasting a stored hash into a
     * client and getting a different failure than they expect.
     */
    public static function looksLikeAToken(string $candidate): bool
    {
        return preg_match('/^[0-9a-f]{' . (self::BYTES * 2) . '}$/', $candidate) === 1;
    }

    /** Does a stored hash belong to this token? Constant time. */
    public function matchesHash(string $storedHash): bool
    {
        return hash_equals($this->hash, $storedHash);
    }
}
