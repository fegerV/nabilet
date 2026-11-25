<?php

declare(strict_types=1);

namespace Nabilet\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nabilet\Core\Errors\ConflictError;
use Nabilet\Core\Errors\ValidationError;
use Nabilet\Core\Idempotency\IdempotencyDecision;
use Nabilet\Core\Idempotency\IdempotencyHeader;
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
 * the lock: the UNIQUE index on (scope, key_hash) serialises concurrent requests.
 * A loser that lost the race between `find()` and `lock()` must re-read the row
 * and re-apply the decision — swallowing the duplicate INSERT and running the
 * handler anyway would execute the checkout twice.
 */
final class EnsureIdempotency
{
    private const HEADER = IdempotencyHeader::NAME;
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
        $keyHash = $this->policy->keyHash($key);
        if (! $this->lock($scoped, $keyHash, $requestHash, $now)) {
            // Другой запрос успел вставить строку между нашим find() и lock().
            // Хендлер ещё НЕ выполнялся, поэтому безопасно перечитать строку и
            // применить решение заново: проигравший получает IN_FLIGHT/REPLAY,
            // а не второй запуск платной операции.
            $winner = $this->find($scoped, $keyHash);
            if ($winner !== null) {
                $decision = $this->policy->decide($key, $scoped, $requestHash, $winner, $now);
                if ($decision === IdempotencyDecision::REPLAY) {
                    return $this->replay($winner);
                }
                if ($decision === IdempotencyDecision::CONFLICT) {
                    throw ConflictError::idempotencyConflict($key);
                }
                // IN_FLIGHT — победитель ещё исполняет хендлер. Редкий PROCEED
                // (победитель уже освободил лок после 5xx) тоже сводим к 409:
                // клиент повторит запрос и спокойно получит новый лок.
                throw ConflictError::alreadyProcessed(
                    'request',
                    ['detail' => 'A request with this idempotency key is still being processed.']
                );
            }
        }

        $response = $next($request);

        if ($this->policy->shouldRecord($response->getStatusCode())) {
            $this->storeResponse($scoped, $keyHash, $response, $now);
        } else {
            // A 5xx must stay retryable: leaving the lock in place would make a
            // transient failure permanent, so release it by deleting the row.
            $this->release($scoped, $keyHash);
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

    /** True — лок наш; false — строку уже вставил конкурент. */
    private function lock(string $scope, string $keyHash, string $requestHash, \DateTimeImmutable $now): bool
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

            return true;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Конкурент выиграл гонку. Мы ещё НЕ вызывали хендлер, поэтому
            // частичной работы нет; handle() перечитает строку и решит,
            // что делать (IN_FLIGHT / REPLAY / CONFLICT).
            //
            // ПОЧЕМУ НЕ `insertOrIgnore()` И НЕ `createOrFirst()`.
            // Здесь работает query builder, а не Eloquent, поэтому
            // `createOrFirst()` недоступен. `insertOrIgnore()` доступен, но он
            // шире по смыслу: MySQL `INSERT IGNORE` гасит ЛЮБУЮ ошибку строки
            // (обрезанные данные, неверный тип), а не только дубликат. Замок,
            // молча не вставленный из-за обрезанного `request_hash`, — это
            // отсутствующая идемпотентность, которую никто не заметит. `catch`
            // ловит ровно дубликат, и это здесь важно.

            return false;
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

        // `Content-Type: application/json` здесь не передаётся: его ставит
        // `response()->json()`. Литерал в списке лишь повторял значение
        // фреймворка и создавал вид, что тип ответа — решение вызывающего.
        return response()->json($decoded, (int) $record->responseStatus, [
            'Idempotent-Replay' => 'true',
        ]);
    }
}
