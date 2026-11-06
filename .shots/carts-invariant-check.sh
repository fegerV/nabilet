#!/usr/bin/env bash
# Functional proof that the corrected carts uniqueness behaves as the spec now
# declares it. Runs each invariant as its own statement so the error code of the
# failing one is visible.
set -u

DB=nabilet_spec_check
PW=$(grep '^DB_PASSWORD=' /c/Project/nabilet/.env | cut -d= -f2-)

q() { docker exec -i nabilet-mysql mysql -unabilet -p"$PW" "$DB" -N -e "$1" 2>&1 | grep -v "Using a password"; }

ok=0; bad=0
expect_ok()   { local label="$1"; local sql="$2"; local out; out=$(q "$sql")
  if [ -z "$out" ]; then echo "  PASS  $label"; ok=$((ok+1));
  else echo "  FAIL  $label  -> $out"; bad=$((bad+1)); fi; }
expect_1062() { local label="$1"; local sql="$2"; local out; out=$(q "$sql")
  if echo "$out" | grep -q "1062"; then echo "  PASS  $label (rejected with 1062)"; ok=$((ok+1));
  else echo "  FAIL  $label  -> expected 1062, got: ${out:-<no error>}"; bad=$((bad+1)); fi; }

CART="INSERT INTO carts (public_id, cart_token, session_id, status, created_at, updated_at) VALUES"

echo "seed:"
docker exec -i nabilet-mysql mysql -unabilet -p"$PW" "$DB" < /c/Project/nabilet/.shots/carts-invariant-check.sql 2>&1 | grep -v "Using a password"

echo
echo "invariants:"
expect_ok   "one active cart for (token, session)"                 "$CART ('01G00000000000000000000001','tok-A',1,'active',NOW(6),NOW(6))"
expect_1062 "second ACTIVE cart, same (token, session) is rejected" "$CART ('01G00000000000000000000002','tok-A',1,'active',NOW(6),NOW(6))"
expect_ok   "first cart converts to 'converted'"                    "UPDATE carts SET status='converted' WHERE cart_token='tok-A' AND status='active'"
expect_ok   "a new active cart may start after conversion"          "$CART ('01G00000000000000000000003','tok-A',1,'active',NOW(6),NOW(6))"
expect_ok   "SECOND PURCHASE converts too (the original 500)"       "UPDATE carts SET status='converted' WHERE cart_token='tok-A' AND status='active'"
expect_ok   "an 'abandoned' cart is unrestricted"                   "$CART ('01G00000000000000000000004','tok-A',1,'abandoned',NOW(6),NOW(6))"
expect_ok   "a second 'converted' cart is unrestricted"             "$CART ('01G00000000000000000000005','tok-A',1,'converted',NOW(6),NOW(6))"
expect_ok   "token-less active cart keys on the session alone"      "$CART ('01G00000000000000000000006',NULL,1,'active',NOW(6),NOW(6))"
expect_1062 "a second token-less active cart is rejected"           "$CART ('01G00000000000000000000007',NULL,1,'active',NOW(6),NOW(6))"

echo
echo "state:"
q "SELECT id, cart_token, status, active_cart_key FROM carts ORDER BY id"
echo
echo "RESULT: $ok passed, $bad failed"
[ "$bad" -eq 0 ]
