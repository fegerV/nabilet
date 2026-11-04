package com.nabilet.checker.domain.model

/**
 * Tickets are looked up by public id in the offline bundle. The section tells
 * us which array the server put the ticket in; the status is the authoritative
 * ticket state at bundle build time.
 */
enum class BundleTicketSection {
    VALID,
    REVOKED,
}

data class BundleTicketEntry(
    val ticketId: String,
    val number: String,
    val status: String,
    val section: BundleTicketSection,
)

/**
 * Everything the app needs at rest about the active bundle. Ticket bodies live
 * in SQLite (`bundle_tickets`) so a 30 000-ticket bundle does not have to sit in
 * RAM for a whole shift.
 */
data class ActiveBundle(
    val id: String,
    val hash: String,
    val schemaVersion: Int,
    val keyFingerprint: String,
    val devicePublicId: String,
    val orgInternalId: Long,
    val eventInternalId: Long,
    val sessionInternalId: Long?,
    val eventPublicId: String,
    val sessionPublicId: String?,
    val ticketCount: Int,
    val revokedCount: Int,
    val expiresAt: Long,
    val generatedAt: Long?,
    val downloadedAt: Long,
    val status: String,
) {
    fun isExpired(nowMillis: Long): Boolean = nowMillis >= expiresAt

    /** The value the checker API expects in `session_id`. */
    val sessionReference: String?
        get() = sessionInternalId?.toString()
}

data class ParsedBundle(
    val bundle: ActiveBundle,
    val tickets: List<BundleTicketEntry>,
    val canonicalJson: String,
)

/** Envelope fields as they arrive from `GET /api/v1/offline-bundles/{id}`. */
data class ServerBundle(
    val id: String,
    val hash: String,
    val schemaVersion: Int,
    val keyFingerprint: String?,
    val eventPublicId: String,
    val sessionPublicId: String?,
    val ticketCount: Int,
    val revokedCount: Int,
    val expiresAt: String?,
    val generatedAt: String?,
    val status: String,
    val payloadJson: String,
)

enum class BundleParseError {
    INVALID_JSON,
    TOO_LARGE,
    UNSUPPORTED_SCHEMA,
    MISSING_FIELD,
    COUNT_MISMATCH,
    KEY_MISMATCH,
    DUPLICATE_TICKET,
    HASH_MISMATCH,
    BAD_TIMESTAMP,
}

sealed interface BundleParseResult {
    data class Valid(val parsed: ParsedBundle) : BundleParseResult
    data class Invalid(val error: BundleParseError, val detail: String) : BundleParseResult
}
