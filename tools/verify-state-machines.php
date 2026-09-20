<?php

declare(strict_types=1);

/**
 * State machine verification — every declared status must be one the database accepts.
 *
 * Usage:
 *     php tools/verify-state-machines.php
 *
 * WHY THIS EXISTS
 *   The kernel's state machines and the schema's CHECK constraints were written
 *   separately. They drifted, and the drift was invisible until runtime: MySQL
 *   rejects the row with a constraint violation the first time a customer cancels
 *   an order or a session completes — in production, under load, on the one path
 *   that customers actually hit.
 *
 *   Three distinct kinds of drift were found here, which is why this tool is not
 *   just a lint:
 *     1. spelling       — 'canceled' in PHP vs 'cancelled' in the CHECK
 *     2. renamed state  — 'finished' in PHP vs 'closed'/'completed' in the CHECK
 *     3. invented state — 'refunded' on payments, which the CHECK does not allow
 *
 * WHAT IT DOES
 *   Parses `CHECK (status IN (...))` out of nabilet_core_spec/migrations.sql for
 *   every table that has one, then asserts that each state a machine declares is
 *   in that table's list — and that the machine's initial state is too.
 *
 * Tables whose status column has no CHECK (events, inventory_items) are reported
 * as UNCONSTRAINED, not passed: an unconstrained status column is a decision to
 * make deliberately, not a clean bill of health.
 */

require __DIR__ . '/../tests/autoload.php';

use Nabilet\Modules\Events\StateMachines\EventStateMachine;
use Nabilet\Modules\Inventory\StateMachines\InventoryItemStateMachine;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Payments\StateMachines\RefundStateMachine;
use Nabilet\Modules\Sessions\StateMachines\SessionStateMachine;
use Nabilet\Modules\Tickets\StateMachines\TicketStateMachine;

$specFile = dirname(__DIR__) . '/nabilet_core_spec/migrations.sql';

$passed = 0;
$failed = 0;
$notes = [];

/**
 * table => state machine class that owns its `status` column.
 *
 * `seat_holds` is deliberately absent: it has no status column at all (it uses
 * released_at / converted_at timestamps), so HoldStateMachine was removed rather
 * than verified — see the note in the summary.
 */
$machines = [
    'sessions' => SessionStateMachine::class,
    'orders' => OrderStateMachine::class,
    'tickets' => TicketStateMachine::class,
    'payments' => PaymentStateMachine::class,
    'refunds' => RefundStateMachine::class,
    'events' => EventStateMachine::class,
    'inventory_items' => InventoryItemStateMachine::class,
];

// ── parse the spec ───────────────────────────────────────────────────────────
$specSql = (string) file_get_contents($specFile);

$allowed = [];   // table => list<string>
$unconstrained = [];

preg_match_all('/ALTER TABLE\s+`?(\w+)`?\s+(.*?);\s*(?:\n|$)/si', $specSql, $alters, PREG_SET_ORDER);

foreach ($alters as $alter) {
    $table = $alter[1];

    // CHECK (status IN ( ... )) — bodies may span lines.
    if (preg_match('/CHECK\s*\(\s*status\s+IN\s*\((.*?)\)\s*\)/si', $alter[2], $m)) {
        preg_match_all("/'([^']+)'/", $m[1], $vals);

        foreach ($vals[1] as $value) {
            $allowed[$table][$value] = true;
        }
    }
}

// 010 re-adds ck_tickets_status to widen it, so values arrive more than once.
foreach ($allowed as $table => $values) {
    $allowed[$table] = array_keys($values);
}

// Which tables in the map have a status column but no CHECK?
foreach (array_keys($machines) as $table) {
    if (! isset($allowed[$table])) {
        $unconstrained[] = $table;
    }
}

echo "\nNABILET Core — state machine verification\n";
echo str_repeat('─', 74), "\n\n";

foreach ($machines as $table => $class) {
    printf("  %-18s %s\n", $table, substr($class, strrpos($class, '\\') + 1));

    if (! isset($allowed[$table])) {
        printf("      ~ no CHECK on status — machine is unenforced\n");
        $notes[] = sprintf('%s.status has no CHECK constraint; %s is not enforced by the database', $table, substr($class, strrpos($class, '\\') + 1));

        continue;
    }

    $machine = $class::make();
    $declared = $machine->states();
    $dbList = $allowed[$table];

    $unknown = array_values(array_diff($declared, $dbList));
    $initial = $machine->initial();

    if ($unknown !== []) {
        $failed++;
        printf("      ✗ declared but the database rejects: %s\n", implode(', ', $unknown));
        printf("        database allows: %s\n", implode(', ', $dbList));
    } else {
        $passed++;
        printf("      ✓ all %d states match the CHECK\n", count($declared));
    }

    if (! in_array($initial, $dbList, true)) {
        $failed++;
        printf("      ✗ initial state \"%s\" is not accepted by the database\n", $initial);
    }
}

echo "\n" . str_repeat('─', 74), "\n";
printf("  %d machines verified, %d failed\n", $passed, $failed);

if ($notes !== []) {
    echo "\n  Notes:\n";

    foreach ($notes as $note) {
        echo '    - ' . $note . "\n";
    }
}

echo "\n";
exit($failed > 0 ? 1 : 0);
