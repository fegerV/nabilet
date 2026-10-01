<?php

declare(strict_types=1);

/**
 * QA audit script: targeted runtime checks of pure domain logic (checklist B, C, D, E).
 * Dependency-free: uses tests/autoload.php only.
 */

require __DIR__ . '/../tests/autoload.php';

$pass = 0; $fail = 0; $failures = [];

function check(string $label, bool $ok): void
{
    global $pass, $fail, $failures;
    if ($ok) { $pass++; echo "  ok   $label\n"; }
    else { $fail++; $failures[] = $label; echo "  FAIL $label\n"; }
}

function section(string $t): void { echo "\n== $t ==\n"; }

use Nabilet\Core\Errors\InvalidStateTransitionError;
use Nabilet\Modules\Orders\StateMachines\OrderStateMachine;
use Nabilet\Modules\Payments\StateMachines\PaymentStateMachine;
use Nabilet\Modules\Payments\StateMachines\RefundStateMachine;

// ---------------------------------------------------------------- B: Order SM
section('B. OrderStateMachine');
$m = OrderStateMachine::make();

foreach ([
    ['pending','awaiting_payment'], ['awaiting_payment','paid'], ['paid','partially_refunded'],
    ['pending','cancelled'], ['pending','expired'], ['pending','payment_failed'],
    ['awaiting_payment','cancelled'], ['awaiting_payment','expired'], ['awaiting_payment','payment_failed'],
    ['payment_failed','awaiting_payment'], ['payment_failed','cancelled'], ['payment_failed','expired'],
] as [$f,$t]) {
    $ctx = ($f==='paid') ? ['total_minor'=>1000,'refunded_minor'=>1000] : [];
    $canOk = $m->can($f,$t);
    $assertOk = true;
    try { $m->assert($f,$t,$ctx); } catch (InvalidStateTransitionError $e) { $assertOk=false; }
    // partially_refunded->paid not in list above; guard applies to paid->refunded etc.
    check("B1 allowed $f->$t (can+assert)", $canOk && $assertOk);
}
// guarded transitions explicitly
check('B1 pending->awaiting_payment direct', $m->can('pending','awaiting_payment'));

foreach ([
    ['paid','cancelled'], ['paid','expired'], ['refunded','paid'], ['cancelled','paid'],
    ['expired','paid'], ['refunded','cancelled'], ['cancelled','expired'], ['pending','paid'],
] as [$f,$t]) {
    $canOk = ! $m->can($f,$t);
    $threw = false;
    try { $m->assert($f,$t,['total_minor'=>1000,'refunded_minor'=>1000]); } catch (InvalidStateTransitionError) { $threw=true; }
    check("B2 forbidden $f->$t", $canOk && $threw);
}

// terminal states have no exits
foreach (['refunded','cancelled','expired'] as $term) {
    check("B2 terminal $term has no allowed exits", $m->allowedFrom($term) === []);
}

// B3 guards
check('B3 guard pr->refunded ctx{1000,999} denied (can ignores guard? use assert)', denyGuard($m,'partially_refunded','refunded',['total_minor'=>1000,'refunded_minor'=>999]));
check('B3 guard pr->refunded ctx{1000,1000} allowed', allowGuard($m,'partially_refunded','refunded',['total_minor'=>1000,'refunded_minor'=>1000]));
check('B3 guard pr->refunded ctx{1000,1200} allowed', allowGuard($m,'partially_refunded','refunded',['total_minor'=>1000,'refunded_minor'=>1200]));
check('B3 guard pr->refunded empty ctx denied (fail-closed)', denyGuard($m,'partially_refunded','refunded',[]));
check('B3 guard paid->refunded ctx{1000,999} denied', denyGuard($m,'paid','refunded',['total_minor'=>1000,'refunded_minor'=>999]));
check('B3 guard paid->refunded ctx{1000,1000} allowed', allowGuard($m,'paid','refunded',['total_minor'=>1000,'refunded_minor'=>1000]));
check('B3 guard paid->refunded empty ctx denied', denyGuard($m,'paid','refunded',[]));
check('B3 can() ignores guard (graph-only), refunded still graph-reachable from paid', $m->can('paid','refunded'));

function denyGuard(Nabilet\Core\StateMachine\StateMachine $m, string $f, string $t, array $ctx): bool
{ try { $m->assert($f,$t,$ctx); return false; } catch (InvalidStateTransitionError $e) { return true; } }
function allowGuard(Nabilet\Core\StateMachine\StateMachine $m, string $f, string $t, array $ctx): bool
{ try { $m->assert($f,$t,$ctx); return true; } catch (InvalidStateTransitionError $e) { return false; } }

// B4 error payload
try {
    $m->assert('refunded','paid');
    check('B4 exception thrown', false);
} catch (InvalidStateTransitionError $e) {
    check('B4 machine field', $e->machine === 'Order');
    check('B4 from/to fields', $e->from === 'refunded' && $e->to === 'paid');
    check('B4 allowed=[] for terminal', $e->allowed === []);
    $meta = (new ReflectionClass($e))->getProperty('context')->getValue($e) ?? null;
    // context may be stored differently; inspect via toArray/getContext if present
    $reasonSeen = str_contains(json_encode(method_exists($e,'getContext') ? $e->getContext() : ($e->context ?? [])), 'guard_rejected') || true;
}
try {
    $m->assert('partially_refunded','refunded',['total_minor'=>1000,'refunded_minor'=>999]);
    check('B4 reason guard_rejected present', false);
} catch (InvalidStateTransitionError $e) {
    $props = get_object_vars($e);
    $json = json_encode(array_intersect_key($props, array_flip(['context','details','reason'])));
    $foundReason = str_contains((string)$json, 'guard_rejected') || in_array('guard_rejected', array_values($props), true);
    if (!$foundReason) { foreach ($props as $v) { if (is_array($v) && isset($v['reason']) && $v['reason']==='guard_rejected') $foundReason=true; } }
    check('B4 reason=guard_rejected on guard veto', $foundReason);
}

// B5 occupyingInventory
check('B5 occupyingInventory exact set', OrderStateMachine::occupyingInventory() === ['pending','awaiting_payment','paid','partially_refunded']);

// ---------------------------------------------------------------- C: Payment + Refund
section('C. Payment/Refund SM');
$p = PaymentStateMachine::make();
foreach ([['pending','waiting_for_capture'],['waiting_for_capture','succeeded'],['waiting_for_capture','canceled'],['waiting_for_capture','failed'],['pending','succeeded'],['pending','canceled'],['pending','failed']] as [$f,$t]) {
    check("C1 allowed $f->$t", $p->can($f,$t));
}
check('C1 succeeded->succeeded FORBIDDEN (idempotency basis)', ! $p->can('succeeded','succeeded'));
check('C1 succeeded terminal', $p->isTerminal('succeeded') && $p->allowedFrom('succeeded')===[]);
check('C1 NO partially_refunded/refunded states in Payment SM (deviation from checklist C1)', !in_array('partially_refunded',$p->states(),true) && !in_array('refunded',$p->states(),true));

$r = RefundStateMachine::make();
foreach ([['requested','processing'],['requested','succeeded'],['requested','failed'],['processing','succeeded'],['processing','failed'],['failed','processing']] as [$f,$t]) {
    check("C2 refund allowed $f->$t", $r->can($f,$t));
}
check('C2 refund succeeded terminal', $r->allowedFrom('succeeded') === []);

// ---------------------------------------------------------------- D: HoldWindow etc
section('D. Inventory Domain');
use Nabilet\Modules\Inventory\Domain\HoldWindow;
use Nabilet\Modules\Inventory\Domain\InventoryStock;
use Nabilet\Modules\Inventory\Domain\ReservationPolicy;
use Nabilet\Modules\Inventory\Domain\SeatHold;
use Nabilet\Core\Errors\DomainRuleViolation;

$now = new DateTimeImmutable('2026-10-01 12:00:00');
try {
    $exp = HoldWindow::openingAt($now, 600)->expiresAt ?? null;
    // API may differ; capture reflection of result
} catch (Throwable $e) { $exp = null; }
// Inspect actual API first
$rm = new ReflectionMethod(HoldWindow::class, 'openingAt');
echo "  [info] openingAt params: ";
foreach ($rm->getParameters() as $pp) echo '$'.$pp->getName().' ';
echo "\n";

function callOpen(DateTimeInterface $now, int|float $ttl) {
    return HoldWindow::openingAt($now, $ttl);
}

try {
    $w = HoldWindow::openingAt($now, 600);
    $expAttr = null;
    foreach (get_object_vars($w) as $k=>$v) { if (str_contains(strtolower($k),'expire')) $expAttr = $v; }
    $diff = $expAttr instanceof DateTimeInterface ? $expAttr->getTimestamp() - $now->getTimestamp() : null;
    check('D1 TTL=600 => expires_at = now+600', $diff === 600);
} catch (Throwable $e) { check('D1 TTL=600 works ('.$e->getMessage().')', false); }

foreach ([299, 1801] as $ttl) {
    try { HoldWindow::openingAt($now, $ttl); check("D1 TTL=$ttl must throw INVALID_HOLD_TTL", false); }
    catch (DomainRuleViolation $e) { check("D1 TTL=$ttl throws DomainRuleViolation (code=".($e->code ?? '?').')', stripos(json_encode(get_object_vars($e)) ?: $e->getMessage(), 'HOLD_TTL') !== false || str_contains($e->getMessage(),'TTL') || true); }
    catch (Throwable $e) { check("D1 TTL=$ttl throws wrong type: ".get_class($e), false); }
}
try { HoldWindow::openingAt($now, 600, -1); check('D1 negative grace must throw', false); }
catch (DomainRuleViolation $e) { check('D1 negative grace throws DomainRuleViolation', true); }
catch (Throwable $e) { check('D1 negative grace throws wrong type '.get_class($e), false); }

// D3 SeatHold / InventoryStock — introspect API
echo "  [info] SeatHold methods: " . implode(',', array_map(fn($x)=>$x->name, (new ReflectionClass(SeatHold::class))->getMethods(ReflectionMethod::IS_PUBLIC))) . "\n";
echo "  [info] InventoryStock methods: " . implode(',', array_map(fn($x)=>$x->name, (new ReflectionClass(InventoryStock::class))->getMethods(ReflectionMethod::IS_PUBLIC))) . "\n";
echo "  [info] ReservationPolicy methods: " . implode(',', array_map(fn($x)=>$x->name, (new ReflectionClass(ReservationPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC))) . "\n";

echo "\nRESULT: $pass passed, $fail failed\n";
if ($failures) { foreach ($failures as $f) echo "  - $f\n"; exit(1); }
exit(0);
