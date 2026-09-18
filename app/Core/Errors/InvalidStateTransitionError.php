<?php

declare(strict_types=1);

namespace Nabilet\Core\Errors;

/**
 * A state machine rejected a transition.
 *
 * Carries the full transition attempt so the audit log and the admin UI can show
 * exactly what was tried: "cannot move order 1234 from refunded to paid".
 */
class InvalidStateTransitionError extends AppError
{
    /** @param list<string> $allowed @param array<string, mixed> $context */
    public function __construct(
        public readonly string $machine,
        public readonly string $from,
        public readonly string $to,
        public readonly array $allowed = [],
        array $context = [],
    ) {
        parent::__construct(
            sprintf(
                'Cannot transition %s from "%s" to "%s".%s',
                $machine,
                $from,
                $to,
                $allowed === [] ? ' No transitions are allowed from this state.' : ' Allowed: ' . implode(', ', $allowed) . '.'
            ),
            'INVALID_STATE_TRANSITION',
            409,
            ['machine' => $machine, 'from' => $from, 'to' => $to, 'allowed' => $allowed] + $context
        );
    }
}
