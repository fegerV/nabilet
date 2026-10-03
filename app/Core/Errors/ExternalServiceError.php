<?php

declare(strict_types=1);

namespace Nabilet\Core\Errors;

/**
 * An upstream dependency (payment provider, Telegram, mailer, AI provider) failed.
 *
 * Always operational and always retryable in principle — but the caller must
 * decide. `retryable` tells the queue/job layer whether a retry is worthwhile:
 * a 502 from YooKassa is, a 401 because the shop id is wrong is not.
 */
class ExternalServiceError extends AppError
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $service,
        string $message,
        public readonly bool $retryable = true,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            strtoupper($service) . '_FAILED',
            502,
            ['service' => $service, 'retryable' => $retryable] + $context,
            true,
            $previous
        );
    }
}
