<?php

declare(strict_types=1);

/**
 * Repro: a packed IP address silently truncates when bound as a string.
 * =====================================================================
 * The ТЗ declares `ip_address VARBINARY(16)` on `user_sessions`, `login_logs` and
 * `audit_logs`. On PostgreSQL that is `bytea`, and binding the packed bytes as an
 * ordinary string — which is what PDO does by default, and what Laravel's query
 * builder does for every string — **stores only the bytes up to the first NUL**:
 *
 *     inet_pton('127.0.0.1')  ->  stored as 7f          (1 byte, not 4)
 *     inet_pton('10.0.0.1')   ->  stored as 0a          (1 byte, not 4)
 *
 * No error, no warning. The audit trail looks populated and is wrong. An IPv6
 * address, which begins with a non-zero byte, survives — so the bug hits exactly
 * the private IPv4 ranges a production deployment sits behind.
 *
 * Run it against a live database:
 *
 *     php tools/repro-binary-ip.php
 *
 * It writes and removes its own rows; the row count is asserted at the end.
 *
 * The fix is `Nabilet\Core\Support\PackedIp::toSqlLiteral()`, which emits a hex
 * literal the server decodes instead of a bound string. Cases 2-5 below are the
 * forms that work, so the tool also documents the alternatives.
 */

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Nabilet\Core\Support\PackedIp;

echo "\nRepro — packed IP truncation on a binary column\n";
echo "──────────────────────────────────────────────────────────────────────────\n\n";

$driver = DB::connection()->getDriverName();

echo "  driver: {$driver}\n";

if ($driver !== 'pgsql') {
    echo "\n  This repro targets PostgreSQL, where the column is `bytea` and the\n";
    echo "  truncation happens. On MySQL the column is VARBINARY and the same bind\n";
    echo "  is exact — which is precisely why the bug is easy to miss.\n\n";

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
            ->selectRaw("length(ip_address) as len, encode(ip_address, 'hex') as hex")
            ->first();

        printf("  %-34s len=%-3s hex=%s\n", $label, $row->len, $row->hex);

        DB::table('user_sessions')->where('id', $id)->delete();
    } catch (Throwable $e) {
        printf("  %-34s FAILED: %s\n", $label, $e->getMessage());
    }
};

$packed = (string) inet_pton('127.0.0.1');

echo "  expected for 127.0.0.1: len=4 hex=7f000001\n\n";

echo "  [1] the bug — bind the packed bytes as an ordinary string\n";
$attempt('plain string bind', $packed);

echo "\n  [2] decode() on the hex — what PackedIp emits for pgsql\n";
$attempt("decode(hex, 'hex')", DB::raw("decode('" . bin2hex($packed) . "', 'hex')"));

echo "\n  [3] the PostgreSQL hex literal\n";
$attempt('\\x…::bytea', DB::raw("'\\x" . bin2hex($packed) . "'::bytea"));

echo "\n  [4] a 10.x address — mostly zero bytes\n";
$ten = (string) inet_pton('10.0.0.1');
printf("      expected: len=4 hex=%s\n", bin2hex($ten));
$attempt("decode() for 10.0.0.1", DB::raw("decode('" . bin2hex($ten) . "', 'hex')"));

echo "\n  [5] an IPv6 address — survives the bug, which is why it hides\n";
$six = (string) inet_pton('2001:db8::1');
printf("      expected: len=16 hex=%s\n", bin2hex($six));
$attempt('decode() for 2001:db8::1', DB::raw("decode('" . bin2hex($six) . "', 'hex')"));

echo "\n  what PackedIp produces:\n";
printf("      toSqlLiteral('pgsql', '127.0.0.1')  = %s\n", (string) PackedIp::toSqlLiteral('pgsql', '127.0.0.1'));
printf("      toSqlLiteral('mysql', '127.0.0.1')  = %s\n", (string) PackedIp::toSqlLiteral('mysql', '127.0.0.1'));
printf("      toSqlLiteral('pgsql', 'not-an-ip')  = %s\n", var_export(PackedIp::toSqlLiteral('pgsql', 'not-an-ip'), true));

$final = (int) DB::table('user_sessions')->count();

echo "\n  cleanup: user_sessions {$baseline} -> {$final}\n\n";

exit($final === $baseline ? 0 : 1);
