<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Packed IP addresses, and the SQL that stores them without losing bytes.
 *
 * WHY THIS EXISTS
 *   The ТЗ declares `ip_address VARBINARY(16)` on `user_sessions`, `login_logs`
 *   and `audit_logs` — the packed `inet_pton()` form, so that 4 bytes hold an
 *   IPv4 address and 16 hold an IPv6 one. On PostgreSQL that column is `bytea`,
 *   and **binding the packed bytes as an ordinary string silently truncates them
 *   at the first NUL byte**: `inet_pton('127.0.0.1')` was stored as `7f` — one
 *   byte instead of four — with no error and no warning. A 10.x.x.x address,
 *   which is mostly zero bytes, is stored as a single byte. Reproduced in
 *   `tools/repro-binary-ip.php`, which also shows the two forms that do work.
 *
 *   A silent truncation is worse than a failure: the audit trail looks populated
 *   and is wrong. So the packed value is never bound as a string — it is emitted
 *   as a hex literal the server itself decodes, which is exact on both engines.
 *
 * WHY IT RETURNS A STRING AND NOT A QUERY EXPRESSION
 *   `app/Core/Support` is framework-free (guarded by `tools/verify-purity.php`),
 *   so this cannot return `Illuminate\Database\Query\Expression`. It returns the
 *   SQL text and the caller wraps it. That also makes every rule here testable
 *   with nothing but a PHP binary, which is where the truncation bug would
 *   otherwise have hidden.
 *
 * READING IS THE CALLER'S PROBLEM, DELIBERATELY
 *   There is no `unpack()` that takes a column value, because what PDO hands back
 *   for a binary column differs per driver: PostgreSQL returns the hex text form
 *   (`\x7f000001`), MySQL returns the raw bytes. A single reader would be wrong
 *   for one of them. `unpack()` therefore takes raw bytes, and a caller that has
 *   a column value must decode it for its own driver first.
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
     * The result is meant to be used as a *raw* expression — an
     * `Illuminate\Database\Query\Expression`, or inlined into a statement — and
     * never as a bound parameter. Binding is the bug this method exists to avoid.
     *
     * The interpolated text is `bin2hex()` output, so it is `[0-9a-f]` only and
     * cannot carry SQL.
     */
    public static function toSqlLiteral(string $driver, ?string $ip): ?string
    {
        $packed = self::pack($ip);

        if ($packed === null) {
            return null;
        }

        $hex = bin2hex($packed);

        // PostgreSQL has no UNHEX(); MySQL has no decode(). Anything that is
        // neither (SQLite, SQL Server) has no binary type worth guessing at, so
        // UNHEX() is emitted and the failure is loud rather than silent.
        return $driver === 'pgsql'
            ? sprintf("decode('%s', 'hex')", $hex)
            : sprintf("UNHEX('%s')", $hex);
    }

    /**
     * The packed bytes in the form PostgreSQL's `bytea` output uses, so that a
     * value read back from a column can be recognised as binary.
     *
     * PostgreSQL returns `\x` followed by hex when `bytea_output = hex`, which has
     * been the default since 9.0. Returning null for anything else keeps a raw
     * MySQL value from being mistaken for that form.
     */
    public static function unpackPostgresHex(?string $columnValue): ?string
    {
        if ($columnValue === null || ! str_starts_with($columnValue, '\x')) {
            return null;
        }

        $hex = substr($columnValue, 2);

        if ($hex === '' || preg_match('/^[0-9a-f]+$/i', $hex) !== 1 || strlen($hex) % 2 !== 0) {
            return null;
        }

        return self::unpack((string) hex2bin($hex));
    }
}
