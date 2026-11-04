package com.nabilet.checker.domain.bundle

import com.nabilet.checker.domain.model.ActiveBundle
import com.nabilet.checker.domain.model.BundleParseError
import com.nabilet.checker.domain.model.BundleParseResult
import com.nabilet.checker.domain.model.BundleTicketEntry
import com.nabilet.checker.domain.model.BundleTicketSection
import com.nabilet.checker.domain.model.ParsedBundle
import com.nabilet.checker.domain.model.ServerBundle
import com.nabilet.checker.domain.util.CryptoUtils
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject
import java.time.Instant
import java.time.OffsetDateTime

/**
 * Turns the `data` object of `GET /api/v1/offline-bundles/{id}` into a verified
 * bundle.
 *
 * Trust model:
 *
 *  - the payload is hashed by the server and the hash is returned next to it;
 *  - this parser re-computes the hash over the canonical bytes and refuses a
 *    bundle whose bytes do not match;
 *  - that proves the payload was not altered after the server built it, and
 *    nothing more. The hash itself is not signed, so TLS is the transport trust
 *    anchor. This is a property of the current backend contract, not a choice
 *    of the app — see docs/DESIGN.md.
 */
object BundleParser {

    const val SUPPORTED_SCHEMA = 1

    /** Refuse pathological payloads before they can exhaust the heap. */
    private const val MAX_PAYLOAD_CHARS = 16 * 1024 * 1024
    private const val MAX_TICKETS = 50_000

    fun parse(server: ServerBundle, downloadedAt: Long): BundleParseResult {
        if (server.payloadJson.length > MAX_PAYLOAD_CHARS) {
            return invalid(BundleParseError.TOO_LARGE, "payload exceeds $MAX_PAYLOAD_CHARS characters")
        }
        if (server.id.isBlank()) return invalid(BundleParseError.MISSING_FIELD, "bundle id is empty")
        if (server.hash.length != 64 || server.hash.any { it !in HEX }) {
            return invalid(BundleParseError.MISSING_FIELD, "bundle hash is not a sha-256 hex digest")
        }

        val payload = try {
            JSONObject(server.payloadJson)
        } catch (e: JSONException) {
            return invalid(BundleParseError.INVALID_JSON, e.message ?: "payload is not valid json")
        }

        if (!payload.has("version")) {
            return invalid(BundleParseError.MISSING_FIELD, "payload.version is missing")
        }
        val payloadVersion = payload.optInt("version", -1)
        if (payloadVersion != SUPPORTED_SCHEMA) {
            return invalid(
                BundleParseError.UNSUPPORTED_SCHEMA,
                "payload.version=$payloadVersion, app supports $SUPPORTED_SCHEMA",
            )
        }
        if (server.schemaVersion != payloadVersion) {
            return invalid(
                BundleParseError.UNSUPPORTED_SCHEMA,
                "envelope schema_version=${server.schemaVersion} != payload.version=$payloadVersion",
            )
        }

        val device = payload.optString("device", "")
        if (device.isBlank()) return invalid(BundleParseError.MISSING_FIELD, "payload.device is empty")

        if (!payload.has("org") || payload.isNull("org")) {
            return invalid(BundleParseError.MISSING_FIELD, "payload.org is missing")
        }
        val org = payload.optLong("org", Long.MIN_VALUE)
        if (org == Long.MIN_VALUE) return invalid(BundleParseError.MISSING_FIELD, "payload.org is not a number")

        if (!payload.has("event") || payload.isNull("event")) {
            return invalid(BundleParseError.MISSING_FIELD, "payload.event is missing")
        }
        val event = payload.optLong("event", Long.MIN_VALUE)
        if (event == Long.MIN_VALUE) return invalid(BundleParseError.MISSING_FIELD, "payload.event is not a number")

        val session: Long? = if (payload.isNull("session")) {
            null
        } else {
            val value = payload.optLong("session", Long.MIN_VALUE)
            if (value == Long.MIN_VALUE) {
                return invalid(BundleParseError.MISSING_FIELD, "payload.session is not a number")
            }
            value
        }

        val key = payload.optString("key", "")
        if (key.isBlank()) return invalid(BundleParseError.MISSING_FIELD, "payload.key is empty")
        if (!server.keyFingerprint.isNullOrBlank() && server.keyFingerprint != key) {
            return invalid(
                BundleParseError.KEY_MISMATCH,
                "envelope key '${server.keyFingerprint}' != payload key '$key'",
            )
        }

        val validArray = payload.optJSONArray("valid")
            ?: return invalid(BundleParseError.MISSING_FIELD, "payload.valid is missing")
        val revokedArray = payload.optJSONArray("revoked")
            ?: return invalid(BundleParseError.MISSING_FIELD, "payload.revoked is missing")

        if (validArray.length() + revokedArray.length() > MAX_TICKETS) {
            return invalid(BundleParseError.TOO_LARGE, "bundle carries too many tickets")
        }

        val valid = parseTickets(validArray, BundleTicketSection.VALID)
            ?: return invalid(BundleParseError.MISSING_FIELD, "a valid ticket entry is malformed")
        val revoked = parseTickets(revokedArray, BundleTicketSection.REVOKED)
            ?: return invalid(BundleParseError.MISSING_FIELD, "a revoked ticket entry is malformed")

        if (server.ticketCount != valid.size) {
            return invalid(
                BundleParseError.COUNT_MISMATCH,
                "ticket_count=${server.ticketCount}, valid entries=${valid.size}",
            )
        }
        if (server.revokedCount != revoked.size) {
            return invalid(
                BundleParseError.COUNT_MISMATCH,
                "revoked_count=${server.revokedCount}, revoked entries=${revoked.size}",
            )
        }

        val seen = HashSet<String>(valid.size + revoked.size)
        for (ticket in valid) {
            if (!seen.add(ticket.ticketId)) {
                return invalid(BundleParseError.DUPLICATE_TICKET, "duplicate ticket ${ticket.ticketId}")
            }
        }
        for (ticket in revoked) {
            if (!seen.add(ticket.ticketId)) {
                return invalid(BundleParseError.DUPLICATE_TICKET, "duplicate ticket ${ticket.ticketId}")
            }
        }

        val canonical = BundleCanonical.build(
            version = payloadVersion,
            device = device,
            org = org,
            event = event,
            session = session,
            key = key,
            valid = valid,
            revoked = revoked,
        )
        val actualHash = CryptoUtils.sha256Hex(canonical)
        if (!CryptoUtils.constantTimeEquals(actualHash, server.hash.lowercase())) {
            return invalid(
                BundleParseError.HASH_MISMATCH,
                "payload hash does not match the envelope",
            )
        }

        if (server.expiresAt.isNullOrBlank()) {
            return invalid(BundleParseError.MISSING_FIELD, "expires_at is missing")
        }
        val expiresAt = parseTimestamp(server.expiresAt)
            ?: return invalid(BundleParseError.BAD_TIMESTAMP, "expires_at is not ISO-8601")

        val generatedAt = if (server.generatedAt.isNullOrBlank()) {
            null
        } else {
            parseTimestamp(server.generatedAt)
                ?: return invalid(BundleParseError.BAD_TIMESTAMP, "generated_at is not ISO-8601")
        }

        return BundleParseResult.Valid(
            ParsedBundle(
                bundle = ActiveBundle(
                    id = server.id,
                    hash = server.hash.lowercase(),
                    schemaVersion = server.schemaVersion,
                    keyFingerprint = key,
                    devicePublicId = device,
                    orgInternalId = org,
                    eventInternalId = event,
                    sessionInternalId = session,
                    eventPublicId = server.eventPublicId,
                    sessionPublicId = server.sessionPublicId,
                    ticketCount = valid.size,
                    revokedCount = revoked.size,
                    expiresAt = expiresAt,
                    generatedAt = generatedAt,
                    downloadedAt = downloadedAt,
                    status = server.status.ifBlank { "unknown" },
                ),
                tickets = valid + revoked,
                canonicalJson = canonical,
            ),
        )
    }

    private fun parseTickets(array: JSONArray, section: BundleTicketSection): List<BundleTicketEntry>? {
        val out = ArrayList<BundleTicketEntry>(array.length())
        for (index in 0 until array.length()) {
            val item = array.optJSONObject(index) ?: return null
            val id = item.optString("id", "")
            val number = item.optString("n", "")
            val status = item.optString("s", "")
            if (id.isBlank() || status.isBlank()) return null
            out.add(BundleTicketEntry(id, number, status, section))
        }
        return out
    }

    private fun parseTimestamp(value: String): Long? =
        runCatching { OffsetDateTime.parse(value).toInstant().toEpochMilli() }.getOrNull()
            ?: runCatching { Instant.parse(value).toEpochMilli() }.getOrNull()

    private fun invalid(error: BundleParseError, detail: String): BundleParseResult =
        BundleParseResult.Invalid(error, detail)

    private val HEX = "0123456789abcdefABCDEF".toCharArray()
}
