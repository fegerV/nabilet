<?php

declare(strict_types=1);

namespace Nabilet\Modules\Auth\Services;

use Illuminate\Database\Query\Expression;
use Nabilet\Core\Support\PackedIp;
use Nabilet\Modules\Auth\Domain\SessionPolicy;
use Nabilet\Modules\Auth\Domain\SessionToken;
use Nabilet\Modules\Auth\Domain\UserSession as SessionRecord;
use Nabilet\Modules\Core\Models\LoginLog;
use Nabilet\Modules\Core\Models\UserSession as UserSessionRow;

/**
 * Issuing, honouring and ending bearer sessions (ТЗ §5, §6).
 *
 * This is the only place in the application that writes `user_sessions`. The rules
 * about whether a session may exist at all — no expiry means unenforceable, an
 * expiry before creation means incoherent, and a session can only be ended by
 * deleting the row — live in `Domain\SessionPolicy`, where they are testable
 * without a database. This class is the I/O half: it builds the domain object,
 * asks the policy, and performs the write the answer calls for.
 *
 * WHAT IT REFUSES TO DO
 *   Issue a session with no `expires_at`. The column is nullable, so the database
 *   would accept it, and the policy says a session that cannot expire is not a
 *   long session but an unbounded one. Rather than write such a row and trust
 *   every later reader to reject it, `issue()` throws: a caller asking for an
 *   unenforceable session has a bug, not a use case.
 *
 * WHY A MALFORMED TOKEN NEVER REACHES THE DATABASE
 *   `authenticate()` checks the shape first. The hash lookup is the real check, but
 *   a header of arbitrary length should not become a query, and an operator who
 *   pastes a *stored hash* into a client should get "not a token" rather than a
 *   confusing miss.
 */
final class SessionIssuer
{
    /**
     * Absolute lifetime of a freshly issued token.
     *
     * A POLICY NUMBER, and deliberately not a constant in `SessionPolicy`: neither
     * the ТЗ nor the contract fixes it, so the domain refuses to decide it — the
     * same call that was made about the privacy SLA and the service fee. It lives
     * here, where an operational default belongs, so the number is greppable and
     * can later come from configuration without touching a rule.
     */
    public const DEFAULT_TTL_DAYS = 30;

    /** Column format for the `datetime:Y-m-d H:i:s.u` casts on both models. */
    private const STAMP_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly SessionPolicy $policy = new SessionPolicy(),
    ) {
    }

    /**
     * Open a session and return the token to hand to the client.
     *
     * The token is returned in plaintext exactly once and is never stored: what
     * the database holds is its sha256, so a dump of `user_sessions` is not a set
     * of usable credentials.
     *
     * @throws \LogicException if asked for a session the policy refuses.
     */
    public function issue(
        int|string $userId,
        ClientContext $client = new ClientContext(),
        ?\DateTimeImmutable $expiresAt = null,
        ?\DateTimeImmutable $now = null,
    ): string {
        $now ??= new \DateTimeImmutable();
        $expiresAt ??= $now->modify('+' . self::DEFAULT_TTL_DAYS . ' days');

        $token = SessionToken::generate();

        $decision = $this->policy->issueDecision(new SessionRecord(
            id: 0,
            userId: $userId,
            tokenHash: $token->hash,
            createdAt: $now,
            expiresAt: $expiresAt,
            deviceName: $client->deviceName,
        ));

        if (! $decision->isAllowed()) {
            throw new \LogicException(sprintf(
                'Refusing to issue an unenforceable session (%s): %s',
                $decision->verdict,
                (string) $decision->reason,
            ));
        }

        $row = new UserSessionRow();

        $row->user_id = $userId;
        $row->session_token_hash = $token->hash;
        $row->device_name = $client->deviceName;
        $row->user_agent = $client->userAgent;
        $row->ip_address = $this->binaryIp($row, $client->ipAddress);
        $row->last_seen_at = null;
        $row->expires_at = self::stamp($expiresAt);
        // The model sets `$timestamps = false`, so `created_at` is ours to write.
        // It is NOT NULL in the schema and nothing else will fill it.
        $row->created_at = self::stamp($now);

        $row->save();

        return $token->plain;
    }

    /**
     * The session behind a token, or null if it may not be honoured.
     *
     * Null covers every refusal — unknown token, no expiry, expired — because the
     * caller is an authentication guard and "who is this?" has one negative
     * answer. The distinctions live in `SessionPolicy` and are reported there.
     *
     * A live session has its `last_seen_at` moved forward, which is what that
     * column is for: it is how a stolen token becomes visible after the fact.
     */
    public function authenticate(string $plainToken, ?\DateTimeImmutable $now = null): ?UserSessionRow
    {
        $now ??= new \DateTimeImmutable();

        if (! SessionToken::looksLikeAToken($plainToken)) {
            return null;
        }

        $row = UserSessionRow::query()
            ->where('session_token_hash', SessionToken::hashOf($plainToken))
            ->first();

        if ($row === null) {
            return null;
        }

        $record = $this->record($row);

        // An unreadable row fails closed rather than being treated as valid.
        if ($record === null || ! $this->policy->authenticateDecision($record, $now)->isAllowed()) {
            return null;
        }

        $this->touch($row, $now);

        return $row;
    }

    /** Move `last_seen_at` forward. Never touches `expires_at`. */
    public function touch(UserSessionRow $row, ?\DateTimeImmutable $now = null): void
    {
        $stamp = self::stamp($now ?? new \DateTimeImmutable());

        UserSessionRow::query()->whereKey($row->getKey())->update(['last_seen_at' => $stamp]);

        $row->last_seen_at = $stamp;
    }

    /**
     * End one session.
     *
     * `user_sessions` has no revocation column — proven on MySQL 8.4, where the
     * count of columns able to record one was 0 and `UPDATE … SET revoked_at`
     * failed with 1054 — so this is a DELETE, and the audit line is written first
     * because the row being removed is the only record that the session existed.
     */
    public function revoke(
        UserSessionRow $row,
        ClientContext $client = new ClientContext(),
        ?\DateTimeImmutable $now = null,
        ?string $identifier = null,
    ): bool {
        $now ??= new \DateTimeImmutable();

        $record = $this->record($row);

        if ($record === null || ! $this->policy->revocationDecision($record)->isAllowed()) {
            return false;
        }

        $this->auditLogout($row, $client, $now, $identifier);

        return UserSessionRow::query()->whereKey($row->getKey())->delete() > 0;
    }

    /** End the session a token belongs to. False if there was no such session. */
    public function revokeByPlainToken(
        string $plainToken,
        ClientContext $client = new ClientContext(),
        ?\DateTimeImmutable $now = null,
        ?string $identifier = null,
    ): bool {
        if (! SessionToken::looksLikeAToken($plainToken)) {
            return false;
        }

        $row = UserSessionRow::query()
            ->where('session_token_hash', SessionToken::hashOf($plainToken))
            ->first();

        return $row !== null && $this->revoke($row, $client, $now, $identifier);
    }

    /** End every session of one user — "log out everywhere". Returns the count. */
    public function revokeAllFor(int|string $userId): int
    {
        return UserSessionRow::query()->where('user_id', $userId)->delete();
    }

    /**
     * The audit line for an ended session, written BEFORE the DELETE.
     *
     * OPEN DECISION (docs/REVIEW-spec-bundle.md §3.25): `login_logs` has no event
     * column, so this row cannot be told apart from a successful login except by
     * the absence of a `failure_code`. `audit_logs` does have an `action` column
     * and would be the better home, but `SessionPolicy::revocationDecision()`
     * names `login_logs`, and the domain rule is followed rather than quietly
     * overridden. The `identifier` is passed in by the caller because the session
     * row does not carry it.
     */
    public function auditLogout(
        UserSessionRow $row,
        ClientContext $client = new ClientContext(),
        ?\DateTimeImmutable $now = null,
        ?string $identifier = null,
    ): void {
        $log = new LoginLog();

        $log->user_id = $row->user_id;
        $log->identifier = $identifier;
        $log->success = true;
        $log->ip_address = $this->binaryIp($log, $client->ipAddress);
        $log->user_agent = $client->userAgent ?? $row->user_agent;
        $log->failure_code = null;
        $log->created_at = self::stamp($now ?? new \DateTimeImmutable());

        $log->save();
    }

    /**
     * The packed bytes, as SQL the server decodes.
     *
     * Binding them as a string truncates at the first NUL byte on PostgreSQL —
     * `127.0.0.1` became `7f` — silently. See `Nabilet\Core\Support\PackedIp`.
     */
    private function binaryIp(UserSessionRow|LoginLog $model, ?string $ipAddress): ?Expression
    {
        $literal = PackedIp::toSqlLiteral($model->getConnection()->getDriverName(), $ipAddress);

        return $literal === null ? null : new Expression($literal);
    }

    /**
     * The row as the domain sees it, or null when it cannot be read coherently.
     *
     * `created_at` is NOT NULL in the schema, so a null one means the row is
     * damaged; the caller fails closed rather than inventing a creation time.
     */
    private function record(UserSessionRow $row): ?SessionRecord
    {
        $createdAt = self::immutable($row->created_at);

        if ($createdAt === null) {
            return null;
        }

        return new SessionRecord(
            id: (int) $row->getKey(),
            userId: (int) $row->user_id,
            tokenHash: (string) $row->session_token_hash,
            createdAt: $createdAt,
            expiresAt: self::immutable($row->expires_at),
            lastSeenAt: self::immutable($row->last_seen_at),
            deviceName: $row->device_name === null ? null : (string) $row->device_name,
        );
    }

    /**
     * Eloquent casts these columns to a mutable `Carbon`, and the domain declares
     * `\DateTimeImmutable`. `Carbon` is NOT a `DateTimeImmutable` — it extends the
     * mutable `DateTime` — so passing one straight through is a TypeError, not a
     * subtle bug. The conversion is explicit for that reason.
     */
    private static function immutable(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    private static function stamp(\DateTimeImmutable $moment): string
    {
        return $moment->format(self::STAMP_FORMAT);
    }
}
