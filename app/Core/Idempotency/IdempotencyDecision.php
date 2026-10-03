<?php

declare(strict_types=1);

namespace Nabilet\Core\Idempotency;

/**
 * What to do with a request that carries an idempotency key.
 *
 * SKIP      no key was sent. The endpoint runs unprotected — deliberate, so that
 *           simple integrations are not forced to implement replay handling.
 * PROCEED   nothing recorded: run the handler and store its response.
 * REPLAY    recorded and finished: return the stored response, run nothing.
 * IN_FLIGHT recorded but unfinished: a duplicate arrived while the first request
 *           was still executing. Refuse rather than execute — running the handler
 *           twice is precisely what the key was meant to prevent.
 * CONFLICT  the key was reused with a different payload. A client bug; returning
 *           the old response would hide it.
 */
final class IdempotencyDecision
{
    public const SKIP = 'skip';
    public const PROCEED = 'proceed';
    public const REPLAY = 'replay';
    public const IN_FLIGHT = 'in_flight';
    public const CONFLICT = 'conflict';
}
