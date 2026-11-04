package com.nabilet.checker.domain.bundle

import com.nabilet.checker.domain.model.BundleTicketEntry

/**
 * Reproduces the bytes PHP hashed in
 * `App\Modules\Checkin\Domain\OfflineBundleBuilder::build()`.
 *
 * This is not "some JSON with the right values". The hash is taken over a
 * specific byte sequence produced by
 * `json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`, so
 * the writer below has to match PHP's key order, absence of whitespace, number
 * formatting and string escaping. A generic JSON serializer (org.json, kotlinx)
 * will not do: key order is not preserved and `/` is escaped by default.
 *
 * Key order is a contract, not an implementation detail:
 *
 *     version, device, org, event, session, key, valid, revoked
 *     ticket: id, n, s
 */
object BundleCanonical {

    fun build(
        version: Int,
        device: String,
        org: Long,
        event: Long,
        session: Long?,
        key: String,
        valid: List<BundleTicketEntry>,
        revoked: List<BundleTicketEntry>,
    ): String {
        val out = StringBuilder(128 + (valid.size + revoked.size) * 64)
        out.append('{')
        out.append("\"version\":").append(version)
        out.append(",\"device\":")
        appendString(out, device)
        out.append(",\"org\":").append(org)
        out.append(",\"event\":").append(event)
        out.append(",\"session\":")
        if (session == null) out.append("null") else out.append(session)
        out.append(",\"key\":")
        appendString(out, key)
        out.append(",\"valid\":")
        appendTickets(out, valid)
        out.append(",\"revoked\":")
        appendTickets(out, revoked)
        out.append('}')
        return out.toString()
    }

    private fun appendTickets(out: StringBuilder, tickets: List<BundleTicketEntry>) {
        out.append('[')
        tickets.forEachIndexed { index, ticket ->
            if (index > 0) out.append(',')
            out.append("{\"id\":")
            appendString(out, ticket.ticketId)
            out.append(",\"n\":")
            appendString(out, ticket.number)
            out.append(",\"s\":")
            appendString(out, ticket.status)
            out.append('}')
        }
        out.append(']')
    }

    /**
     * Mirrors PHP's `json_encode` with `JSON_UNESCAPED_SLASHES` and
     * `JSON_UNESCAPED_UNICODE`:
     *
     *  - `/` is NOT escaped (the flag above);
     *  - non-ASCII is NOT escaped;
     *  - `"`, `\` and control characters are escaped;
     *  - `\b \f \n \r \t` use their short forms;
     *  - other C0 controls become lowercase `\u00xx`;
     *  - U+2028/U+2029 stay escaped for JavaScript safety.
     */
    private fun appendString(out: StringBuilder, value: String) {
        out.append('"')
        for (ch in value) {
            when (ch) {
                '"' -> out.append("\\\"")
                '\\' -> out.append("\\\\")
                '\b' -> out.append("\\b")
                '\u000C' -> out.append("\\f")
                '\n' -> out.append("\\n")
                '\r' -> out.append("\\r")
                '\t' -> out.append("\\t")
                '\u2028' -> out.append("\\u2028")
                '\u2029' -> out.append("\\u2029")
                else -> {
                    if (ch.code < 0x20) {
                        out.append("\\u")
                        out.append(ch.code.toString(16).padStart(4, '0'))
                    } else {
                        out.append(ch)
                    }
                }
            }
        }
        out.append('"')
    }
}
