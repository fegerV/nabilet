<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Modules\Auth\Domain\SessionToken;
use Nabilet\Tests\Support\TestCase;

/**
 * The token and the hash stored for it (ТЗ §5, §6).
 *
 * `user_sessions.session_token_hash` is `CHAR(64) NOT NULL` with
 * `UNIQUE uq_user_sessions_token` — read from the live schema. Two things follow,
 * and both are asserted here rather than assumed:
 *
 *   - what is stored must be exactly 64 characters, or the write fails or pads;
 *   - the plaintext token must never be what is stored, or a dump of
 *     `user_sessions` is a set of usable credentials.
 *
 * The token length is not cosmetic. A 64-byte token is 128 hex characters while
 * its hash is 64, so the two cannot be confused by shape; a 32-byte token would be
 * 64 characters — indistinguishable from the hash it maps to — and pasting the
 * stored value into a client would look like it should work.
 */
final class SessionTokenTest extends TestCase
{
    // ── generation ──────────────────────────────────────────────────────────

    public function testAGeneratedTokenIsAHexStringOfTheDeclaredWidth(): void
    {
        $token = SessionToken::generate();

        $this->assertSame(SessionToken::BYTES * 2, strlen($token->plain));
        $this->assertSame(128, strlen($token->plain));
        $this->assertSame(1, preg_match('/^[0-9a-f]+$/', $token->plain));
    }

    public function testTheHashFitsTheColumnExactly(): void
    {
        // CHAR(64). A shorter or longer digest is a broken write, not a style issue.
        $token = SessionToken::generate();

        $this->assertSame(SessionToken::HASH_LENGTH, strlen($token->hash));
        $this->assertSame(64, strlen($token->hash));
        $this->assertSame(hash('sha256', $token->plain), $token->hash);
    }

    public function testTheTokenAndItsHashAreDifferentWidths(): void
    {
        // The whole reason for 64 bytes rather than 32: a token must not be
        // mistakable for the value stored for it.
        $token = SessionToken::generate();

        $this->assertNotSame(strlen($token->plain), strlen($token->hash));
        $this->assertSame(strlen($token->hash) * 2, strlen($token->plain));
    }

    public function testTwoGeneratedTokensDoNotCollide(): void
    {
        $first = SessionToken::generate();
        $second = SessionToken::generate();

        $this->assertNotSame($first->plain, $second->plain);
        $this->assertNotSame($first->hash, $second->hash);
    }

    public function testHashingIsDeterministic(): void
    {
        $plain = 'a-plain-token-value';

        $this->assertSame(SessionToken::hashOf($plain), SessionToken::fromPlain($plain)->hash);
        $this->assertSame(SessionToken::fromPlain($plain)->hash, SessionToken::fromPlain($plain)->hash);
    }

    // ── the shape check ─────────────────────────────────────────────────────

    public function testAGeneratedTokenLooksLikeAToken(): void
    {
        $this->assertTrue(SessionToken::looksLikeAToken(SessionToken::generate()->plain));
    }

    public function testAStoredHashDoesNotLookLikeAToken(): void
    {
        // The confusion this prevents: an operator reads a value out of
        // `user_sessions` and tries to use it as a credential. It is 64 characters,
        // so it must be rejected on shape rather than by a confusing lookup miss.
        $hash = SessionToken::generate()->hash;

        $this->assertFalse(SessionToken::looksLikeAToken($hash));
    }

    public function testUppercaseIsNotAccepted(): void
    {
        // Tokens are emitted as lowercase hex by `bin2hex()`. Accepting uppercase
        // would mean hashing a string the client never received, so the lookup
        // would miss anyway — better to reject it clearly.
        $this->assertFalse(SessionToken::looksLikeAToken(strtoupper(SessionToken::generate()->plain)));
    }

    public function testMalformedCandidatesAreRejected(): void
    {
        $valid = SessionToken::generate()->plain;

        $this->assertFalse(SessionToken::looksLikeAToken(''));
        $this->assertFalse(SessionToken::looksLikeAToken('not-a-token'));
        $this->assertFalse(SessionToken::looksLikeAToken(substr($valid, 0, 127)));
        $this->assertFalse(SessionToken::looksLikeAToken($valid . 'a'));
        $this->assertFalse(SessionToken::looksLikeAToken(str_repeat('z', 128)));
        $this->assertFalse(SessionToken::looksLikeAToken(str_repeat('0x', 64)));
        $this->assertFalse(SessionToken::looksLikeAToken(' ' . $valid));
    }

    // ── comparison ──────────────────────────────────────────────────────────

    public function testATokenMatchesItsOwnHash(): void
    {
        $token = SessionToken::generate();

        $this->assertTrue($token->matchesHash($token->hash));
        $this->assertTrue(SessionToken::fromPlain($token->plain)->matchesHash($token->hash));
    }

    public function testATokenDoesNotMatchAnotherHash(): void
    {
        $token = SessionToken::generate();
        $other = SessionToken::generate();

        $this->assertFalse($token->matchesHash($other->hash));
        $this->assertFalse($token->matchesHash(str_repeat('0', 64)));
    }

    public function testADifferentTokenDoesNotMatchTheHash(): void
    {
        $token = SessionToken::generate();
        $tampered = substr($token->plain, 0, -1) . (str_ends_with($token->plain, 'a') ? 'b' : 'a');

        $this->assertFalse(SessionToken::fromPlain($tampered)->matchesHash($token->hash));
    }
}
