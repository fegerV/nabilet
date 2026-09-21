<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Domain;

use Nabilet\Core\Errors\DomainRuleViolation;
use App\Modules\HallSchemas\Domain\SchemaVersion;
use App\Modules\Sessions\StateMachines\SessionStateMachine;

/**
 * The seating facts about one session at a point in time.
 *
 * This exists because the database cannot answer the only question that matters:
 * does the map this session points at still contain the seats it has already
 * sold?
 *
 * `sessions.schema_version_id` is a plain foreign key to
 * `hall_schema_versions(id)`. `inventory_items.seat_id` is a plain foreign key to
 * `seats(id)`. Each is satisfied on its own, and neither says anything about the
 * other. The seat row still exists after the session is re-pointed, so both
 * foreign keys remain happy while the session's map no longer contains the seat
 * it sold.
 *
 * `inventoryCount` stands in for "SELECT COUNT(*) FROM inventory_items". It is
 * passed in because this class must not touch the database, and because the
 * number itself is irrelevant — only whether it is zero.
 */
final class SessionSeating
{
    public function __construct(
        public readonly int $sessionId,
        public readonly int $hallId,
        public readonly string $status,
        public readonly SchemaVersion $schemaVersion,
        public readonly int $inventoryCount = 0,
    ) {
        if ($sessionId <= 0 || $hallId <= 0) {
            throw new DomainRuleViolation(
                'A session and its hall must both have positive ids.',
                'INVALID_SESSION_SEATING'
            );
        }

        if ($inventoryCount < 0) {
            throw new DomainRuleViolation(
                sprintf('Inventory count cannot be negative, got %d.', $inventoryCount),
                'INVALID_SESSION_SEATING'
            );
        }
    }

    /**
     * Once inventory rows exist, the session's map is no longer free to change:
     * every row names a seat that belongs to one particular schema version.
     */
    public function hasInventory(): bool
    {
        return $this->inventoryCount > 0;
    }

    /** `completed` and `cancelled` are terminal in ck_sessions_status. */
    public function isTerminal(): bool
    {
        return in_array(
            $this->status,
            [SessionStateMachine::COMPLETED, SessionStateMachine::CANCELLED],
            true
        );
    }
}
