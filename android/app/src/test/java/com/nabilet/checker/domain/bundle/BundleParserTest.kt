package com.nabilet.checker.domain.bundle

import com.nabilet.checker.domain.model.BundleParseError
import com.nabilet.checker.domain.model.BundleParseResult
import com.nabilet.checker.domain.model.BundleTicketEntry
import com.nabilet.checker.domain.model.BundleTicketSection
import com.nabilet.checker.domain.model.ServerBundle
import com.nabilet.checker.domain.util.CryptoUtils
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class BundleParserTest {

    @Test
    fun `parses a consistent bundle`() {
        val payload = canonical()
        val result = BundleParser.parse(serverBundle(payload), DOWNLOADED_AT)

        assertTrue(result is BundleParseResult.Valid)
        val parsed = (result as BundleParseResult.Valid).parsed
        assertEquals(2, parsed.bundle.ticketCount)
        assertEquals(1, parsed.bundle.revokedCount)
        assertEquals(3, parsed.tickets.size)
        assertEquals(EXPIRES_AT_MILLIS, parsed.bundle.expiresAt)
        assertEquals("1001", parsed.bundle.sessionReference)
        assertEquals(DEVICE, parsed.bundle.devicePublicId)
    }

    @Test
    fun `refuses a payload altered after it was hashed`() {
        val payload = canonical().replace("A-001", "A-999")

        val result = BundleParser.parse(serverBundle(payload), DOWNLOADED_AT)

        assertError(result, BundleParseError.HASH_MISMATCH)
    }

    @Test
    fun `refuses a ticket count that does not match the list`() {
        val payload = canonical()

        val result = BundleParser.parse(
            serverBundle(payload, ticketCount = 99),
            DOWNLOADED_AT,
        )

        assertError(result, BundleParseError.COUNT_MISMATCH)
    }

    @Test
    fun `refuses a key fingerprint that does not match the payload`() {
        val payload = canonical()

        val result = BundleParser.parse(
            serverBundle(payload, keyFingerprint = "sha256:deadbeef"),
            DOWNLOADED_AT,
        )

        assertError(result, BundleParseError.KEY_MISMATCH)
    }

    @Test
    fun `refuses an unsupported schema`() {
        val payload = canonical()

        val result = BundleParser.parse(
            serverBundle(payload, schemaVersion = 2),
            DOWNLOADED_AT,
        )

        assertError(result, BundleParseError.UNSUPPORTED_SCHEMA)
    }

    @Test
    fun `refuses duplicated tickets across the valid and revoked lists`() {
        // A self-consistent payload: the hash is correct, so the only thing
        // that can reject it is the duplicate check.
        val payload = BundleCanonical.build(
            version = 1,
            device = DEVICE,
            org = ORG,
            event = EVENT,
            session = SESSION,
            key = KEY,
            valid = listOf(
                BundleTicketEntry("dup", "A-1", "issued", BundleTicketSection.VALID),
            ),
            revoked = listOf(
                BundleTicketEntry("dup", "A-1", "used", BundleTicketSection.REVOKED),
            ),
        )

        val result = BundleParser.parse(
            serverBundle(payload, ticketCount = 1, revokedCount = 1),
            DOWNLOADED_AT,
        )

        assertError(result, BundleParseError.DUPLICATE_TICKET)
    }

    @Test
    fun `refuses a bundle without an expiry`() {
        val payload = canonical()

        val result = BundleParser.parse(
            serverBundle(payload, expiresAt = null),
            DOWNLOADED_AT,
        )

        assertError(result, BundleParseError.MISSING_FIELD)
    }

    @Test
    fun `refuses a broken timestamp`() {
        val payload = canonical()

        val result = BundleParser.parse(
            serverBundle(payload, expiresAt = "next tuesday"),
            DOWNLOADED_AT,
        )

        assertError(result, BundleParseError.BAD_TIMESTAMP)
    }

    @Test
    fun `refuses a hash that is not sha-256 hex`() {
        val payload = canonical()

        val result = BundleParser.parse(
            serverBundle(payload, hash = "not-a-hash"),
            DOWNLOADED_AT,
        )

        assertError(result, BundleParseError.MISSING_FIELD)
    }

    @Test
    fun `refuses malformed json`() {
        val result = BundleParser.parse(serverBundle("{not json"), DOWNLOADED_AT)

        assertError(result, BundleParseError.INVALID_JSON)
    }

    private fun assertError(result: BundleParseResult, expected: BundleParseError) {
        assertTrue("expected $expected, got $result", result is BundleParseResult.Invalid)
        assertEquals(expected, (result as BundleParseResult.Invalid).error)
    }

    private fun canonical(): String = BundleCanonical.build(
        version = 1,
        device = DEVICE,
        org = ORG,
        event = EVENT,
        session = SESSION,
        key = KEY,
        valid = listOf(
            BundleTicketEntry("01JTICKET0000000000000001", "A-001", "issued", BundleTicketSection.VALID),
            BundleTicketEntry("01JTICKET0000000000000002", "A-002", "issued", BundleTicketSection.VALID),
        ),
        revoked = listOf(
            BundleTicketEntry("01JTICKET0000000000000003", "A-003", "used", BundleTicketSection.REVOKED),
        ),
    )

    private fun serverBundle(
        payload: String,
        hash: String = CryptoUtils.sha256Hex(payload),
        schemaVersion: Int = 1,
        keyFingerprint: String? = KEY,
        ticketCount: Int = 2,
        revokedCount: Int = 1,
        expiresAt: String? = "2099-01-01T00:00:00+00:00",
        generatedAt: String? = "2026-01-01T00:00:00+00:00",
    ) = ServerBundle(
        id = BUNDLE_ID,
        hash = hash,
        schemaVersion = schemaVersion,
        keyFingerprint = keyFingerprint,
        eventPublicId = "01JEVENT0000000000000000",
        sessionPublicId = "01JSESSION000000000000000",
        ticketCount = ticketCount,
        revokedCount = revokedCount,
        expiresAt = expiresAt,
        generatedAt = generatedAt,
        status = "generated",
        payloadJson = payload,
    )

    private companion object {
        const val BUNDLE_ID = "01JBUNDLE0000000000000000"
        const val DEVICE = "01JDEVICE0000000000000000"
        const val KEY = "sha256:8f14e45fceea167a5a36dedd4bea2543"
        const val ORG = 7L
        const val EVENT = 42L
        const val SESSION = 1001L
        const val DOWNLOADED_AT = 1_800_000_000_000L
        const val EXPIRES_AT_MILLIS = 4_070_908_800_000L
    }
}
