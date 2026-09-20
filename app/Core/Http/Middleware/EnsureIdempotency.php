<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Idempotency\IdempotencyDecision;
use Nabilet\Core\Idempotency\IdempotencyPolicy;
use Nabilet\Core\Idempotency\IdempotencyRecord;
use Nabilet\Core\Idempotency\IdempotencyScope;
use Nabilet\Core\Tenancy\OrganizationContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotent write endpoints (ТЗ §28, §76).
 *
 * Usage:
 *
 *     Route::post('/orders', ...)->middleware('idempotent:orders.create');
 *
 * A client sends `Idempotency-Key: <opaque>`. The first request executes and its
 * response is recorded; a replay with the same key and the same payload returns the
 * RECORDED response instead of executing again. A replay with the same key but a
 * DIFFERENT payload is rejected — that is a client bug, and silently reusing the old
 * result would hide it.
 *
 * The rules live in IdempotencyPolicy, not here. This class only does I/O: read the
 * row, apply the decision, write the row. Splitting it that way is what lets the
 * dangerous cases (in-flight duplicate, expired replay, 5xx) be tested without a
 * database or an HTTP stack.
 *
 * THE ROW IS WRITTEN BEFORE THE HANDLER RUNS, with no response yet. That insert is
 * the lock: if a duplicate arrives concurrently, the UNIQUE index on
 * (scope, key_hash) makes it fail, and the loser is told IN_FLIGHT instead of
 * running the checkout a second time.
 */
final class EnsureIdempotency
{
    private const HEADER = 'Idempotency-Key';
    private const TABLE = 'idempotency_keys';

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly IdempotencyPolicy $policy = new IdempotencyPolicy(),
    ) {
    }

    public function handle(Request $request, Closure $next, string $scope = ''): Response
    {
        $key = $request->header(self::HEADER);

        if (! is_string($key) || $key === '') {
            return $next($request);
        }

        if (strlen($key) > IdempotencyPolicy::MAX_KEY_LENGTH) {
            throw new ValidationError(
                [self::HEADER => ['Idempotency key must not exceed ' . IdempotencyPolicy::MAX_KEY_LENGTH . ' characters.']]
            );
        }

        $scope = $scope !== '' ? $scope : $request->method() . ':' . $request->path();
        $scoped = IdempotencyScope::make($this->context, $scope);
        $requestHash = $this->policy->requestHash($request->getContent());
        $now = new \DateTimeImmutable('now');

        $existing = $this->find($scoped, $this->policy->keyHash($key));

        switch ($this->policy->decide($key, $scoped, $requestHash, $existing, $now)) {
            case IdempotencyDecision::REPLAY:
                return $this->replay($existing);

            case IdempotencyDecision::CONFLICT:
                throw ConflictError::idempotencyConflict($key);

            case IdempotencyDecision::IN_FLIGHT:
                // 409, not 425: the client should retry, and 409 is already in the
                // contract's error vocabulary for "you did this already".
                throw ConflictError::alreadyProcessed(
                    'request',
                    ['detail' => 'A request with this idempotency key is still being processed.']
                );

            case IdempotencyDecision::SKIP:
                return $next($request);
        }

        // PROCEED — take the lock first, so a concurrent duplicate sees IN_FLIGHT
        // rather than racing us into a second execution.
        $this->lock($scoped, $this->policy->keyHash($key), $requestHash, $now);

        $response = $next($request);

        if ($this->policy->shouldRecord($response->getStatusCode())) {
            $this->storeResponse($scoped, $this->policy->keyHash($key), $response, $now);
        } else {
            // A 5xx must stay retryable: leaving the lock in place would make a
            // transient failure permanent, so release it by deleting the row.
            $this->release($scoped, $this->policy->keyHash($key));
        }

        return $response;
    }

    private function find(string $scope, string $keyHash): ?IdempotencyRecord
    {
        $row = DB::table(self::TABLE)
            ->where('scope', $scope)
            ->where('key_hash', $keyHash)
            ->first();

        return $row === null ? null : IdempotencyRecord::fromArray((array) $row);
    }

    private function lock(string $scope, string $keyHash, string $requestHash, \DateTimeImmutable $now): void
    {
        try {
            DB::table(self::TABLE)->insert([
                'scope' => $scope,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'response_status' => null,
                'response_body' => null,
                'locked_at' => $now,
                'expires_at' => $this->policy->expiresAt($now),
                'created_at' => $now,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // A concurrent request won the race. Nothing to do: it owns the lock
            // and this request has not executed anything yet, so there is no
            // partial work to undo.
        }
    }

    private function storeResponse(string $scope, string $keyHash, Response $response, \DateTimeImmutable $now): void
    {
        DB::table(self::TABLE)
            ->where('scope', $scope)
            ->where('key_hash', $keyHash)
            ->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent() ?: null,
                'expires_at' => $this->policy->expiresAt($now),
            ]);
    }

    private function release(string $scope, string $keyHash): void
    {
        DB::table(self::TABLE)
            ->where('scope', $scope)
            ->where('key_hash', $keyHash)
            ->delete();
    }

    private function replay(?IdempotencyRecord $record): Response
    {
        if ($record === null) {
            throw new \LogicException('REPLAY was decided without a record; this is a bug.');
        }

        $body = $record->responseBody;
        $decoded = is_string($body) && $body !== '' ? json_decode($body, true) : null;

        return response()->json($decoded, (int) $record->responseStatus, [
            'Content-Type' => 'application/json',
            'Idempotent-Replay' => 'true',
        ]);
    }
}
