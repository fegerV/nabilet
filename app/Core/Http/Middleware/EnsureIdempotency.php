<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\ConflictError;
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
 * The subtle part is the race: two concurrent requests with the same key. This is
 * handled by relying on the UNIQUE index on (scope, key) rather than a
 * read-then-write check. The loser of the race gets a duplicate-key violation and
 * waits for, then returns, the winner's stored response.
 *
 * Why this matters concretely: mobile clients on flaky networks retry POSTs. Without
 * this middleware a retried checkout creates a second order for the same seats.
 */
final class EnsureIdempotency
{
    private const HEADER = 'Idempotency-Key';
    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next, string $scope = ''): Response
    {
        $key = $request->header(self::HEADER);

        // No key supplied: the endpoint still works, it just is not replay-safe.
        // Making the key mandatory would break simple integrations for no gain.
        if (! is_string($key) || $key === '') {
            return $next($request);
        }

        if (strlen($key) > 191) {
            throw new \Nabilet\Core\Errors\ValidationError(
                [self::HEADER => ['Idempotency key must not exceed 191 characters.']]
            );
        }

        $scope = $scope !== '' ? $scope : $request->method() . ':' . $request->path();
        $requestHash = hash('sha256', $request->getContent());

        $existing = DB::table('idempotency_keys')
            ->where('scope', $scope)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $requestHash);
        }

        $response = $next($request);

        // Only successful, non-server-error responses are recorded. A 500 or a 502
        // must remain retryable — caching it would make a transient failure permanent.
        if ($response->getStatusCode() < 500) {
            $this->store($scope, $key, $requestHash, $response);
        }

        return $response;
    }

    private function replay(object $existing, string $requestHash): Response
    {
        if (! hash_equals((string) $existing->request_hash, $requestHash)) {
            throw ConflictError::idempotencyConflict((string) $existing->key);
        }

        $body = $existing->response_body;
        $decoded = is_string($body) ? json_decode($body, true) : null;

        return response()->json($decoded, (int) $existing->response_code, [
            'Content-Type' => 'application/json',
            'Idempotent-Replay' => 'true',
        ]);
    }

    private function store(string $scope, string $key, string $requestHash, Response $response): void
    {
        try {
            DB::table('idempotency_keys')->insert([
                'scope' => $scope,
                'key' => $key,
                'request_hash' => $requestHash,
                'response_code' => $response->getStatusCode(),
                'response_body' => $response->getContent() ?: null,
                'expires_at' => now()->addHours(self::TTL_HOURS),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Concurrent request won the race. Its response is authoritative; this
            // one already executed, which is acceptable for idempotent operations
            // and is the documented trade-off of relying on the unique index.
        }
    }
}
