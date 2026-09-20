<?php

declare(strict_types=1);

namespace Nabilet\Modules\Sessions\Domain;

use Nabilet\Modules\HallSchemas\Domain\SchemaVersion;

/**
 * What may be done to a session's hall map (ТЗ §13, §46).
 *
 * PURE: no database. The rules are testable without one, which is the point —
 * the whole reason they are here is that the database does not enforce them.
 *
 * BOTH GAPS BELOW WERE PROVEN BY EXECUTION against MySQL 8.4 with the spec
 * schema loaded, not inferred from reading the DDL:
 *
 *   1. A session was INSERTed pointing at a schema version whose status is
 *      'draft'. MySQL accepted it. Nothing in the schema requires the map a
 *      session sells from to have been published.
 *
 *   2. A session with an existing `inventory_items` row had its
 *      `schema_version_id` UPDATEd to a different version. MySQL accepted it.
 *      After the change the session pointed at version 2 while the sold seat
 *      still belonged to version 1:
 *
 *          session_points_at = 2, seat_belongs_to = 1
 *
 *      Both foreign keys were still satisfied. `inventory_items.seat_id` checks
 *      that the seat exists; it does not check that the seat belongs to the
 *      version this session now points at. The customer keeps a ticket to seat
 *      1 in a hall layout the session no longer shows.
 *
 * THE RULE FOR "PUBLISHED" IS DELIBERATELY `not draft`, NOT `is published`.
 * A map that was published and later archived is still a legitimate map for a
 * session that is already selling: archiving is how a hall supersedes a layout,
 * and older sessions keep selling from the older one. Rejecting archived here
 * would break every session running on a retired-but-valid map. The defect is
 * specifically the draft — a map that can still be edited underneath a sale.
 */
final class SessionSeatingPolicy
{
    /** Creating a session: the map has to belong to the hall it is in. */
    public function canBind(SessionSeating $session, SchemaVersion $version): SeatingDecision
    {
        if ($session->isTerminal()) {
            return SeatingDecision::denied(
                SeatingDecision::SESSION_TERMINAL,
                sprintf('session %d is %s; its seating is historical record.', $session->sessionId, $session->status)
            );
        }

        if ($version->hallId !== $session->hallId) {
            return SeatingDecision::denied(
                SeatingDecision::WRONG_HALL,
                sprintf(
                    'schema version %d belongs to hall %d but the session is in hall %d; '
                    . 'the seats would not be in the room.',
                    $version->version,
                    $version->hallId,
                    $session->hallId
                )
            );
        }

        return SeatingDecision::allowed();
    }

    /**
     * Opening a session for sale: the map must be finished. A draft is still
     * editable, so seats could move or disappear after someone bought one.
     */
    public function canOpenSales(SessionSeating $session): SeatingDecision
    {
        if ($session->isTerminal()) {
            return SeatingDecision::denied(
                SeatingDecision::SESSION_TERMINAL,
                sprintf('session %d is %s and cannot open sales.', $session->sessionId, $session->status)
            );
        }

        if ($session->schemaVersion->isDraft()) {
            return SeatingDecision::denied(
                SeatingDecision::SCHEMA_NOT_PUBLISHED,
                sprintf(
                    'session %d sells from schema version %d, which is still a draft; '
                    . 'its map can be edited while seats are on sale.',
                    $session->sessionId,
                    $session->schemaVersion->version
                )
            );
        }

        return SeatingDecision::allowed();
    }

    /**
     * Changing which map a session uses. Forbidden outright once anything has
     * been generated, because every inventory row names a seat from the old map
     * and nothing downstream will notice the mismatch.
     */
    public function canRebind(SessionSeating $session, SchemaVersion $version): SeatingDecision
    {
        if ($session->isTerminal()) {
            return SeatingDecision::denied(
                SeatingDecision::SESSION_TERMINAL,
                sprintf('session %d is %s; its seating is historical record.', $session->sessionId, $session->status)
            );
        }

        if ($version->id === $session->schemaVersion->id) {
            return SeatingDecision::noChange(
                sprintf('session %d is already bound to schema version %d.', $session->sessionId, $version->version)
            );
        }

        if ($version->hallId !== $session->hallId) {
            return SeatingDecision::denied(
                SeatingDecision::WRONG_HALL,
                sprintf(
                    'schema version %d belongs to hall %d but the session is in hall %d.',
                    $version->version,
                    $version->hallId,
                    $session->hallId
                )
            );
        }

        if ($session->hasInventory()) {
            return SeatingDecision::denied(
                SeatingDecision::INVENTORY_EXISTS,
                sprintf(
                    'session %d already has %d inventory row(s) naming seats from schema '
                    . 'version %d; re-pointing it would leave those seats outside its own map.',
                    $session->sessionId,
                    $session->inventoryCount,
                    $session->schemaVersion->version
                )
            );
        }

        if ($version->isDraft()) {
            return SeatingDecision::denied(
                SeatingDecision::SCHEMA_NOT_PUBLISHED,
                sprintf('schema version %d is a draft and cannot be sold from.', $version->version)
            );
        }

        return SeatingDecision::allowed();
    }
}
