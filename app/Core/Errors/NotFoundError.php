<?php

declare(strict_types=1);

namespace Nabilet\Core\Errors;

/**
 * A requested resource does not exist — or does not exist *for this tenant*.
 *
 * Note the second clause: cross-tenant reads return 404, never 403. A 403 would
 * confirm that the id exists somewhere in the system, leaking the existence of
 * other organizations' data (IDOR/BOLA enumeration).
 */
class NotFoundError extends AppError
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $resource,
        public readonly string|int $resourceId = '',
        array $context = [],
    ) {
        parent::__construct(
            $resourceId === ''
                ? sprintf('%s not found.', $resource)
                : sprintf('%s not found: %s.', $resource, $resourceId),
            strtoupper($resource) . '_NOT_FOUND',
            404,
            $context,
        );
    }
}
