<?php

declare(strict_types=1);

/**
 * Repro: what a packed IP address actually lands as in a binary column.
 * =====================================================================
 * The ТЗ declares `ip_address VARBINARY(16)` on `user_sessions`, `login_logs`
 * and `audit_logs` — the packed `inet_pton()` form, so 4 bytes hold an IPv4
 * address and 16 hold an IPv6 one.
 *
 * That representation is unforgiving in a specific way: the bytes of a private
 * IPv4 address are mostly NUL. `inet_pton('127.0.0.1')` is `7f 00 00 01`, and
 * `inet_pton('10.0.0.1')` is `0a 00 00 01`. If anything in the write path
 * treats those bytes as a C string, the value is cut at the first NUL and stored
 * as a single byte — no error, no warning, and the audit trail looks populated
 * while being wrong.
 *
 * This tool does not assert which failure modes apply. It *measures* what this
 * server does with each write form, so the choice in
 * `Nabilet\Core\Support\PackedIp::toSqlLiteral()` rests on evidence rather than
 * on folklore. Cases 2-4 are the alternatives; case 1 is the plain bind that PDO
 * performs for an ordinary string parameter.
 *
 * Measured on MySQL 8.4.11 (2026-10, driver mysql, `ATTR_EMULATE_PREPARES` false
 * as Laravel sets it): every case lands byte-exact, including the plain bind.
 * The same holds with emulated prepares and with `NO_BACKSLASH_ESCAPES` set. So
 * on this stack the literal form is a guard, not a workaround — it is kept
 * because it removes the dependency on the connection's configuration, which is
 * the part a shared host controls. Re-run this tool after changing either.
 *
 * Run it against a live database:
 *
 *     php tools/repro-binary-ip.php
 *
 * It writes and removes its own rows; the row count is asserted at the end.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Nabilet\Core\Support\PackedIp;

echo "\nRepro — what a packed IP lands as in a binary column\n";
echo "──────────────────────────────────────────────────────────────────────────\n\n";

$driver = DB::connection()->getDriverName();

echo "  driver: {$driver}\n";

if (! in_array($driver, ['mysql', 'mariadb'], true)) {
    echo "\n  This repro targets MySQL/MariaDB, where the column is VARBINARY and the\n";
    echo "  read-back below uses LENGTH()/HEX(). The ТЗ supports no other engine\n";
    echo "  (§3), so there is nothing else to reproduce here.\n\n";

    exit(0);
}

$userId = (int) DB::table('users')->orderBy('id')->value('id');
$baseline = (int) DB::table('user_sessions')->count();

if ($userId === 0) {
    echo "\n  No user row to attach a probe session to; nothing to reproduce.\n\n";

    exit(0);
}

echo "  baseline user_sessions: {$baseline}\n\n";

/** Insert with the given ip_address value, and report what actually landed. */
$attempt = static function (string $label, mixed $ipValue) use ($userId): void {
    try {
        $id = DB::table('user_sessions')->insertGetId([
            'user_id' => $userId,
            'session_token_hash' => str_pad(bin2hex(random_bytes(8)), 64, '0'),
            'expires_at' => (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s.u'),
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'ip_address' => $ipValue,
        ]);

        $row = DB::table('user_sessions')->where('id', $id)
            ->selectRaw('LENGTH(ip_address) as len, HEX(ip_address) as hex')
            ->first();

        printf("  %-34s len=%-3s hex=%s\n", $label, $row->len, $row->hex);

        DB::table('user_sessions')->where('id', $id)->delete();
    } catch (Throwable $e) {
        printf("  %-34s FAILED: %s\n", $label, $e->getMessage());
    }
};

$packed = (string) inet_pton('127.0.0.1');

echo "  expected for 127.0.0.1: len=4 hex=7f000001\n\n";

echo "  [1] plain string bind — what PDO does for every string parameter\n";
$attempt('plain string bind', $packed);

echo "\n  [2] UNHEX() on the hex — what PackedIp emits\n";
$attempt("UNHEX('" . bin2hex($packed) . "')", DB::raw("UNHEX('" . bin2hex($packed) . "')"));

echo "\n  [3] a 10.x address — mostly zero bytes\n";
$ten = (string) inet_pton('10.0.0.1');
printf("      expected: len=4 hex=%s\n", bin2hex($ten));
$attempt("UNHEX() for 10.0.0.1", DB::raw("UNHEX('" . bin2hex($ten) . "')"));

echo "\n  [4] an IPv6 address — no leading NUL byte, so it hides the problem\n";
$six = (string) inet_pton('2001:db8::1');
printf("      expected: len=16 hex=%s\n", bin2hex($six));
$attempt("UNHEX() for 2001:db8::1", DB::raw("UNHEX('" . bin2hex($six) . "')"));

echo "\n  what PackedIp produces:\n";
printf("      toSqlLiteral('127.0.0.1')  = %s\n", (string) PackedIp::toSqlLiteral('127.0.0.1'));
printf("      toSqlLiteral('2001:db8::1') = %s\n", (string) PackedIp::toSqlLiteral('2001:db8::1'));
printf("      toSqlLiteral('not-an-ip')  = %s\n", var_export(PackedIp::toSqlLiteral('not-an-ip'), true));

$final = (int) DB::table('user_sessions')->count();

echo "\n  cleanup: user_sessions {$baseline} -> {$final}\n\n";

exit($final === $baseline ? 0 : 1);
