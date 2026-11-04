package com.nabilet.checker.domain.bundle

import com.nabilet.checker.domain.model.ActiveBundle
import com.nabilet.checker.domain.model.BundleTicketEntry
import com.nabilet.checker.domain.model.BundleTicketSection
import com.nabilet.checker.domain.model.CheckinResult
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class OfflineVerifierTest {

    @Test
    fun `admits an issued ticket from the valid section`() {
        val verdict = decide(ticket = entry("issued", BundleTicketSection.VALID))

        assertEquals(CheckinResult.VALID, verdict.result)
        assertEquals("A-001", verdict.ticketNumber)
        assertEquals(OfflineReason.VALID_FROM_BUNDLE, verdict.reason)
        assertTrue(verdict.result.admits)
    }

    @Test
    fun `refuses a used ticket from the revoked section`() {
        val verdict = decide(ticket = entry("used", BundleTicketSection.REVOKED))

        assertEquals(CheckinResult.ALREADY_USED, verdict.result)
        assertEquals(OfflineReason.REVOKED_STATUS, verdict.reason)
        assertFalse(verdict.result.admits)
    }

    @Test
    fun `maps every revoked status`() {
        assertEquals(
            CheckinResult.ALREADY_USED,
            decide(ticket = entry("used", BundleTicketSection.REVOKED)).result,
        )
        assertEquals(
            CheckinResult.REFUNDED,
            decide(ticket = entry("refunded", BundleTicketSection.REVOKED)).result,
        )
        assertEquals(
            CheckinResult.CANCELLED,
            decide(ticket = entry("cancelled", BundleTicketSection.REVOKED)).result,
        )
        assertEquals(
            CheckinResult.EXPIRED,
            decide(ticket = entry("expired", BundleTicketSection.REVOKED)).result,
        )
        assertEquals(
            CheckinResult.CONFLICT,
            decide(ticket = entry("revoked", BundleTicketSection.REVOKED)).result,
        )
    }

    @Test
    fun `refuses an unknown ticket instead of admitting it`() {
        val verdict = decide(ticket = null)

        assertEquals(CheckinResult.UNKNOWN_TICKET, verdict.result)
        assertEquals(OfflineReason.UNKNOWN_TICKET, verdict.reason)
        assertNull(verdict.ticketNumber)
        assertFalse(verdict.result.admits)
    }

    @Test
    fun `refuses a second scan of a ticket already admitted locally`() {
        val verdict = decide(
            ticket = entry("issued", BundleTicketSection.VALID),
            locallyAdmitted = true,
        )

        assertEquals(CheckinResult.ALREADY_USED, verdict.result)
        assertEquals(OfflineReason.ALREADY_ADMITTED_LOCALLY, verdict.reason)
        // The number still comes from the bundle so the operator can read it.
        assertEquals("A-001", verdict.ticketNumber)
    }

    @Test
    fun `trusts the local admission record even when the bundle knows nothing`() {
        val verdict = decide(ticket = null, locallyAdmitted = true)

        assertEquals(CheckinResult.ALREADY_USED, verdict.result)
    }

    @Test
    fun `trusts the bundle section over an issued status`() {
        // The server never sends this pair. If it ever does, the section is the
        // stronger statement and the ticket must not be admitted.
        val verdict = decide(ticket = entry("issued", BundleTicketSection.REVOKED))

        assertEquals(CheckinResult.CONFLICT, verdict.result)
        assertFalse(verdict.result.admits)
    }

    @Test
    fun `refuses everything when the bundle has expired`() {
        val unavailable = decideUnavailable(
            ticket = entry("issued", BundleTicketSection.VALID),
            now = EXPIRES_AT,
        )

        assertEquals(OfflineUnavailable.EXPIRED_BUNDLE, unavailable.reason)
    }

    @Test
    fun `expiry wins over a local admission record`() {
        val unavailable = decideUnavailable(
            ticket = entry("issued", BundleTicketSection.VALID),
            now = EXPIRES_AT + 1,
            locallyAdmitted = true,
        )

        assertEquals(OfflineUnavailable.EXPIRED_BUNDLE, unavailable.reason)
    }

    @Test
    fun `refuses an event-wide bundle that cannot be reported by session`() {
        val unavailable = decideUnavailable(
            bundle = bundle(sessionInternalId = null),
            ticket = entry("issued", BundleTicketSection.VALID),
        )

        assertEquals(OfflineUnavailable.NO_SESSION, unavailable.reason)
    }

    @Test
    fun `maps statuses for callers that only have a string`() {
        assertEquals(CheckinResult.VALID, OfflineVerifier.resultForStatus("issued"))
        assertEquals(CheckinResult.ALREADY_USED, OfflineVerifier.resultForStatus("used"))
        assertEquals(CheckinResult.REFUNDED, OfflineVerifier.resultForStatus("refunded"))
        assertEquals(CheckinResult.CANCELLED, OfflineVerifier.resultForStatus("cancelled"))
        assertEquals(CheckinResult.EXPIRED, OfflineVerifier.resultForStatus("expired"))
        assertEquals(CheckinResult.CONFLICT, OfflineVerifier.resultForStatus("revoked"))
        // Anything unrecognised fails closed rather than guessing "valid".
        assertEquals(CheckinResult.CONFLICT, OfflineVerifier.resultForStatus("mystery"))
    }

    private fun decide(
        bundle: ActiveBundle = bundle(),
        ticket: BundleTicketEntry?,
        now: Long = NOT_EXPIRED,
        locallyAdmitted: Boolean = false,
    ): OfflineDecision.Verdict {
        val decision = OfflineVerifier.decide(bundle, TICKET_ID, now, locallyAdmitted) { id ->
            ticket?.takeIf { it.ticketId == id }
        }
        assertTrue("expected a verdict, got $decision", decision is OfflineDecision.Verdict)
        return decision as OfflineDecision.Verdict
    }

    private fun decideUnavailable(
        bundle: ActiveBundle = bundle(),
        ticket: BundleTicketEntry?,
        now: Long = NOT_EXPIRED,
        locallyAdmitted: Boolean = false,
    ): OfflineDecision.Unavailable {
        val decision = OfflineVerifier.decide(bundle, TICKET_ID, now, locallyAdmitted) { id ->
            ticket?.takeIf { it.ticketId == id }
        }
        assertTrue("expected an unavailable decision, got $decision", decision is OfflineDecision.Unavailable)
        return decision as OfflineDecision.Unavailable
    }

    private fun entry(status: String, section: BundleTicketSection) = BundleTicketEntry(
        ticketId = TICKET_ID,
        number = "A-001",
        status = status,
        section = section,
    )

    private fun bundle(sessionInternalId: Long? = SESSION_ID) = ActiveBundle(
        id = "01JBUNDLE0000000000000000",
        hash = "7c198be9c28bef7620750c556007ad2151891af67049dba8ab2a1d68ea861bfe",
        schemaVersion = 1,
        keyFingerprint = "sha256:8f14e45fceea167a5a36dedd4bea2543",
        devicePublicId = "01JDEVICE0000000000000000",
        orgInternalId = 7L,
        eventInternalId = 42L,
        sessionInternalId = sessionInternalId,
        eventPublicId = "01JEVENT0000000000000000",
        sessionPublicId = "01JSESSION000000000000000",
        ticketCount = 2,
        revokedCount = 1,
        expiresAt = EXPIRES_AT,
        generatedAt = GENERATED_AT,
        downloadedAt = GENERATED_AT,
        status = "generated",
    )

    private companion object {
        const val TICKET_ID = "01JTICKET0000000000000001"
        const val SESSION_ID = 1001L
        const val GENERATED_AT = 1_800_000_000_000L
        const val EXPIRES_AT = 1_800_086_400_000L
        const val NOT_EXPIRED = EXPIRES_AT - 1
    }
}
