<?php

declare(strict_types=1);

namespace Nabilet\Core\Errors;

/**
 * The request is well-formed but conflicts with current state.
 *
 * This is the workhorse of the commerce core: a seat already held, an order
 * already paid, an idempotency key reused with a different payload. All of these
 * are *expected* outcomes under concurrency, not bugs — so they are operational
 * errors with a precise `errorCode` the caller can act on.
 */
class ConflictError extends AppError
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        string $errorCode = 'CONFLICT',
        array $context = [],
    ) {
        parent::__construct($message, $errorCode, 409, $context);
    }

    /** @param array<string, mixed> $context */
    public static function seatUnavailable(string $inventoryItemId, array $context = []): self
    {
        return new self(
            'This seat is no longer available.',
            'SEAT_UNAVAILABLE',
            ['inventory_item_id' => $inventoryItemId] + $context
        );
    }

    /** @param array<string, mixed> $context */
    public static function holdExpired(string $holdId, array $context = []): self
    {
        return new self(
            'The seat reservation has expired. Please select your seats again.',
            'HOLD_EXPIRED',
            ['hold_id' => $holdId] + $context
        );
    }

    /** @param array<string, mixed> $context */
    public static function idempotencyConflict(string $key, array $context = []): self
    {
        return new self(
            'This idempotency key was already used with a different request payload.',
            'IDEMPOTENCY_KEY_REUSED',
            ['idempotency_key' => $key] + $context
        );
    }

    /** @param array<string, mixed> $context */
    public static function alreadyProcessed(string $what, array $context = []): self
    {
        return new self(
            sprintf('%s has already been processed.', $what),
            strtoupper($what) . '_ALREADY_PROCESSED',
            $context
        );
    }
}
