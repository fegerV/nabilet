<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Nabilet\Modules\Auth\Domain\AccountTokenPurpose;
use Nabilet\Modules\Core\Users\Models\User;

/**
 * Signed, stateless tokens for the password-reset and e-mail-verification flows.
 *
 * WHY SIGNED AND NOT A TABLE
 *   `nabilet_core_spec` defines no `password_reset_tokens` (verified: the live
 *   table had zero columns), and `docs/REVIEW-spec-bundle.md` §3.25.6 records the
 *   plan chosen here — an HMAC over user id + e-mail + purpose + expiry, rather
 *   than a new table the spec does not describe. A signed token needs no
 *   migration, no cleanup job for expired rows, and no second lookup: everything
 *   the verifier needs travels in the token. This is also why there is no
 *   `TokenStorage` model — there is nothing to store.
 *
 * WHAT IS IN THE TOKEN
 *   `Crypt::encryptString()` of a JSON payload: `{u, e, p, x, k}` — user public
 *   id, e-mail at issue time, purpose, expiry (unix), and a short fingerprint of
 *   the password hash. `Crypt` (AES-256-CBC + HMAC) already provides authenticity,
 *   so no separate signature is layered on top; the JSON inside is tamper-evident
 *   because the ciphertext is.
 *
 * WHY IT IS NOT A `user_sessions` ROW
 *   Sessions are bearer credentials that authenticate a request. These tokens are
 *   single-purpose and single-use; putting them in the same store would mean a
 *   stolen reset link reads as a logged-in session. They are deliberately not
 *   interchangeable.
 *
 * WHY THE PASSWORD FINGERPRINT
 *   A reset link mailed an hour ago should stop working the moment the password
 *   changes. `k` is a short hash of the current `users.password`; a reset that
 *   succeeds rewrites the hash, so every older link fails verification with
 *   `expired`. That is the only revocation mechanism, and it is why the token
 *   carries `k` instead of trusting the issuer to revoke.
 *
 * THE THREAT THIS DOES NOT ADDRESS
 *   A token is only as private as the mailbox it was sent to. That is inherent to
 *   every e-mail-based reset and is why the TTL is short (see `DEFAULT_TTL`).
 */
final class AccountTokenService
{
    /** Reset links expire in under an hour; a mailbox is not a secret store. */
    public const DEFAULT_TTL = 1800;

    /**
     * `users.password` is a `VARCHAR(255)` bcrypt hash. Only its first 16 hex
     * characters of a sha256 are kept, so the token does not carry a value from
     * which the password can be attacked offline. It is a change-detector, not a
     * secret: knowing `k` does not reveal the hash.
     */
    private const FINGERPRINT_LENGTH = 16;

    /**
     * Mint a token for a purpose.
     *
     * The e-mail is bound at issue time so a token minted against one address
     * cannot be redeemed after the account's address changed — the same class of
     * change the password fingerprint detects.
     */
    public function issue(
        User $user,
        AccountTokenPurpose $purpose,
        ?\DateTimeInterface $now = null,
        ?int $ttlSeconds = null,
    ): string {
        $now ??= new \DateTimeImmutable();

        return Crypt::encryptString(json_encode([
            'u' => (string) $user->public_id,
            'e' => (string) $user->email,
            'p' => $purpose->value,
            'x' => $now->getTimestamp() + ($ttlSeconds ?? self::DEFAULT_TTL),
            'k' => $this->fingerprint((string) $user->password),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Resolve a token to its user, or null for any reason it may not be honoured.
     *
     * Null covers every refusal — malformed, wrong purpose, unknown user, expired,
     * address changed, password changed — because the caller is an endpoint that
     * has one negative answer. The distinctions are logged by the caller, not
     * returned, so a probe cannot tell "expired" from "no such user".
     */
    public function verify(
        string $token,
        AccountTokenPurpose $purpose,
        ?\DateTimeInterface $now = null,
    ): ?User {
        $payload = $this->decode($token);

        if ($payload === null) {
            return null;
        }

        $now ??= new \DateTimeImmutable();

        // Purpose first: a verification link must never be usable to reset.
        if (($payload['p'] ?? null) !== $purpose->value) {
            return null;
        }

        if (! is_int($payload['x'] ?? null) || $payload['x'] < $now->getTimestamp()) {
            return null;
        }

        $user = User::query()->where('public_id', (string) ($payload['u'] ?? ''))->first();

        if ($user === null) {
            return null;
        }

        // The address and the password at redemption time must still be the ones
        // the token was minted against. Anything else means the link is stale.
        if ((string) $user->email !== (string) ($payload['e'] ?? '')) {
            return null;
        }

        return $this->fingerprint((string) $user->password) === (string) ($payload['k'] ?? '')
            ? $user
            : null;
    }

    /**
     * The decrypted payload, or null when the token is not one we issued.
     *
     * `Crypt::decryptString()` throws on both a tampered ciphertext and a value
     * that was never encrypted; both mean "not a token", which is one answer here.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $token): ?array
    {
        if (trim($token) === '') {
            return null;
        }

        try {
            $json = Crypt::decryptString($token);
        } catch (DecryptException) {
            return null;
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function fingerprint(string $passwordHash): string
    {
        return substr(hash('sha256', $passwordHash), 0, self::FINGERPRINT_LENGTH);
    }
}
