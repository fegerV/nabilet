<?php

declare(strict_types=1);

namespace Nabilet\Core\Errors;

/**
 * A business invariant was violated.
 *
 * Examples: a published hall schema version was modified; a ticket was issued
 * for a session whose sales window has closed; money was combined across
 * currencies. These are not user input errors — they mean the caller tried to do
 * something the domain forbids.
 */
class DomainRuleViolation extends AppError
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        string $errorCode = 'DOMAIN_RULE_VIOLATION',
        array $context = [],
        int $status = 422,
    ) {
        parent::__construct($message, $errorCode, $status, $context);
    }

    /** @param array<string, mixed> $context */
    public static function immutable(string $entity, string $field, array $context = []): self
    {
        return new self(
            sprintf('%s is immutable once published; cannot change "%s".', $entity, $field),
            'IMMUTABLE_RESOURCE',
            ['entity' => $entity, 'field' => $field] + $context
        );
    }

    /** @param array<string, mixed> $context */
    public static function salesClosed(string $sessionId, array $context = []): self
    {
        return new self(
            'Ticket sales for this session are closed.',
            'SALES_CLOSED',
            ['session_id' => $sessionId] + $context
        );
    }
}
