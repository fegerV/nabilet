<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Packed IP addresses, and the SQL that stores them without losing bytes.
 *
 * WHY THIS EXISTS
 *   The ТЗ declares `ip_address VARBINARY(16)` on `user_sessions`, `login_logs`
 *   and `audit_logs` — the packed `inet_pton()` form, so that 4 bytes hold an
 *   IPv4 address and 16 hold an IPv6 one. Those bytes are NUL-heavy:
 *   `inet_pton('127.0.0.1')` is `7f 00 00 01`, and a 10.x.x.x address is
 *   `0a 00 00 01`.
 *
 *   Any layer that treats the value as a C string stops at the first NUL. A
 *   silent truncation is worse than a failure: the audit trail looks populated
 *   and is wrong. So the packed value is never handed over as a string to be
 *   escaped — it is emitted as a hex literal the server itself decodes, which is
 *   exact whatever the connection's prepare mode or `sql_mode` happens to be.
 *
 *   `tools/repro-binary-ip.php` measures both forms against a live database. On
 *   MySQL 8.4 with this driver they currently agree; the literal form is kept
 *   because that agreement is a property of the connection, not of the schema,
 *   and the connection is the part a shared host gets to configure.
 *
 * WHY IT RETURNS A STRING AND NOT A QUERY EXPRESSION
 *   `app/Core/Support` is framework-free (guarded by `tools/verify-purity.php`),
 *   so this cannot return `Illuminate\Database\Query\Expression`. It returns the
 *   SQL text and the caller wraps it. That also makes every rule here testable
 *   with nothing but a PHP binary, which is where the truncation bug would
 *   otherwise have hidden.
 */
final class PackedIp
{
    /** `VARBINARY(16)`: 4 bytes for IPv4, 16 for IPv6. */
    public const MAX_BYTES = 16;

    /**
     * The packed bytes for a textual IP address, or null if it is not one.
     *
     * Returning null for unparseable input is the fail-closed choice: an
     * `ip_address` of null says "not recorded", which is honest, while storing
     * the bytes of the literal string "unknown" would look like a real address.
     */
    public static function pack(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        $packed = @inet_pton(trim($ip));

        if ($packed === false || strlen($packed) > self::MAX_BYTES) {
            return null;
        }

        return $packed;
    }

    /** The textual address for raw packed bytes, or null if they are not one. */
    public static function unpack(?string $bytes): ?string
    {
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $ip = @inet_ntop($bytes);

        return $ip === false ? null : $ip;
    }

    /**
     * SQL that stores $ip in a binary column, or null when there is nothing to
     * store.
     *
     * `UNHEX()` is MySQL/MariaDB — the sole supported target, MySQL 8.4.
     *
     * The result is meant to be used as a *raw* expression — an
     * `Illuminate\Database\Query\Expression`, or inlined into a statement — and
     * never as a bound parameter. Binding is the bug this method exists to avoid.
     *
     * The interpolated text is `bin2hex()` output, so it is `[0-9a-f]` only and
     * cannot carry SQL.
     */
    public static function toSqlLiteral(?string $ip): ?string
    {
        $packed = self::pack($ip);

        if ($packed === null) {
            return null;
        }

        return sprintf("UNHEX('%s')", bin2hex($packed));
    }
}
