<?php

declare(strict_types=1);

namespace Nabilet\Core\Errors;

/**
 * Authentication (401) and authorization (403) failures.
 *
 * 401 and 403 are kept distinct because they demand different client behaviour:
 * 401 → refresh the token and retry; 403 → retrying is pointless, show a
 * permission message. The frontend's interceptor depends on that distinction.
 */
class AuthError extends AppError
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        string $errorCode = 'UNAUTHENTICATED',
        int $status = 401,
        array $context = [],
    ) {
        parent::__construct($message, $errorCode, $status, $context);
    }

    /** @param array<string, mixed> $context */
    public static function unauthenticated(string $message = 'Authentication required.', array $context = []): self
    {
        return new self($message, 'UNAUTHENTICATED', 401, $context);
    }

    /** @param array<string, mixed> $context */
    public static function tokenExpired(array $context = []): self
    {
        return new self('Access token expired.', 'TOKEN_EXPIRED', 401, $context);
    }

    /** @param array<string, mixed> $context */
    public static function forbidden(string $permission, array $context = []): self
    {
        return new self(
            sprintf('Missing required permission: %s.', $permission),
            'FORBIDDEN',
            403,
            ['required_permission' => $permission] + $context
        );
    }

    /** @param array<string, mixed> $context */
    public static function twoFactorRequired(array $context = []): self
    {
        return new self('Two-factor confirmation required.', 'TWO_FACTOR_REQUIRED', 401, $context);
    }
}
