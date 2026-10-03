<?php

declare(strict_types=1);

namespace Nabilet\Core\Errors;

/**
 * Base class for every error NABILET Core raises deliberately.
 *
 * Two properties make this hierarchy worth having over generic \Exception:
 *
 *  1. `errorCode` — a stable, machine-readable SCREAMING_SNAKE identifier
 *     (`SEAT_UNAVAILABLE`, `PAYMENT_ALREADY_CAPTURED`). The frontend, the Android
 *     Checker and partner integrations branch on this, never on the message text.
 *     Messages are for humans and may be translated or reworded at any time.
 *
 *  2. `operational` — distinguishes "the world is as expected, the request was
 *     just wrong" (a seat already sold, a bad promo code → safe to render to the
 *     caller) from "we have a bug" (a null dereference → must be logged and
 *     replaced by a generic 500, never leaked).
 *
 * Deliberately named `errorCode` and not `code`: \Exception already declares
 * `protected int $code`, and redeclaring it with a different type is a fatal
 * error in PHP. The wire format renames it back to `code` — see `toResponse()`.
 *
 * @see docs/ARCHITECTURE.md — "Error model"
 */
class AppError extends \RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 500,
        public readonly array $context = [],
        public readonly bool $operational = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Wire representation mandated by ТЗ §66 and `docs/openapi.yaml` (`ErrorResponse`):
     *
     *     {
     *       "error": {
     *         "code": "SEAT_ALREADY_HELD",
     *         "message": "Место уже забронировано",
     *         "details": {},
     *         "request_id": "…"
     *       }
     *     }
     *
     * Note the deliberate asymmetry: internally the property is `errorCode`
     * (because `code` is taken by \Exception), on the wire it is `code`.
     *
     * `details` is omitted entirely when there is nothing to say, so clients can
     * rely on its absence meaning "no extra context" rather than "empty object".
     *
     * @return array{error: array<string, mixed>}
     */
    public function toResponse(string $requestId = ''): array
    {
        $error = [
            'code' => $this->errorCode,
            'message' => $this->message,
        ];

        if ($this->context !== []) {
            $error['details'] = $this->context;
        }

        if ($requestId !== '') {
            $error['request_id'] = $requestId;
        }

        return ['error' => $error];
    }

    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }
}
