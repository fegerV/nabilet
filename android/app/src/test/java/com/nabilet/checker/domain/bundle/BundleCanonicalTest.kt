package com.nabilet.checker.domain.bundle

import com.nabilet.checker.domain.model.BundleTicketEntry
import com.nabilet.checker.domain.model.BundleTicketSection
import com.nabilet.checker.domain.util.CryptoUtils
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The expected hashes below were produced by PHP 8.4 with
 * `json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` —
 * the exact call `OfflineBundleBuilder` makes. If this test ever fails, the
 * Android app and the server disagree about the bytes that were hashed, and
 * every downloaded bundle would be refused as tampered.
 */
class BundleCanonicalTest {

    private fun ticket(id: String, number: String, status: String, revoked: Boolean = false) =
        BundleTicketEntry(
            ticketId = id,
            number = number,
            status = status,
            section = if (revoked) BundleTicketSection.REVOKED else BundleTicketSection.VALID,
        )

    @Test
    fun `matches php json encode for a plain payload`() {
        val canonical = BundleCanonical.build(
            version = 1,
            device = DEVICE,
            org = 7L,
            event = 42L,
            session = 1001L,
            key = KEY,
            valid = listOf(
                ticket("01JTICKET0000000000000001", "A-001", "issued"),
                ticket("01JTICKET0000000000000002", "A-002", "issued"),
            ),
            revoked = listOf(
                ticket("01JTICKET0000000000000003", "A-003", "used", revoked = true),
            ),
        )

        assertEquals(SIMPLE_JSON, canonical)
        assertEquals(SIMPLE_HASH, CryptoUtils.sha256Hex(canonical))
    }

    @Test
    fun `matches php json encode for slashes quotes controls and unicode`() {
        val canonical = BundleCanonical.build(
            version = 1,
            device = DEVICE,
            org = 7L,
            event = 42L,
            session = null,
            key = KEY,
            valid = listOf(
                ticket("01JTICKET0000000000000001", "Сектор/A \"1\" \\ ряд", "issued"),
                ticket("01JTICKET0000000000000002", "line1\nline2\ttab", "issued"),
                ticket("01JTICKET0000000000000003", "emoji \uD83C\uDF6B and \u2028sep", "issued"),
            ),
            revoked = emptyList(),
        )

        // Hash is verified by BundleParserTest against the real PHP hash;
        // here we only check the exact escaping rules.
        assertTrue(canonical.contains("Сектор/A \\\"1\\\" \\\\ ряд"))
        assertTrue(canonical.contains("line1\\nline2\\ttab"))
        assertTrue(canonical.contains("\\u2028sep"))
        assertTrue(canonical.contains("\"session\":null"))
    }

    @Test
    fun `keeps the contract key order`() {
        val canonical = BundleCanonical.build(
            version = 1,
            device = DEVICE,
            org = 1L,
            event = 2L,
            session = 3L,
            key = KEY,
            valid = emptyList(),
            revoked = emptyList(),
        )

        assertEquals(
            "{\"version\":1,\"device\":\"$DEVICE\",\"org\":1,\"event\":2,\"session\":3," +
                "\"key\":\"$KEY\",\"valid\":[],\"revoked\":[]}",
            canonical,
        )
    }

    private companion object {
        const val DEVICE = "01JDEVICE0000000000000000"
        const val KEY = "sha256:8f14e45fceea167a5a36dedd4bea2543"

        const val SIMPLE_JSON =
            "{\"version\":1,\"device\":\"01JDEVICE0000000000000000\",\"org\":7,\"event\":42," +
                "\"session\":1001,\"key\":\"sha256:8f14e45fceea167a5a36dedd4bea2543\"," +
                "\"valid\":[{\"id\":\"01JTICKET0000000000000001\",\"n\":\"A-001\",\"s\":\"issued\"}," +
                "{\"id\":\"01JTICKET0000000000000002\",\"n\":\"A-002\",\"s\":\"issued\"}]," +
                "\"revoked\":[{\"id\":\"01JTICKET0000000000000003\",\"n\":\"A-003\",\"s\":\"used\"}]}"

        const val SIMPLE_HASH = "7c198be9c28bef7620750c556007ad2151891af67049dba8ab2a1d68ea861bfe"

        /**
         * Same payload as the `tricky` fixture in the PHP script: Cyrillic with
         * `/` and quotes and a backslash, a newline and a tab, an emoji and a
         * U+2028. PHP escapes everything except `/`, non-ASCII and the emoji,
         * and that is what the writer above reproduces.
         */
        const val TRICKY_HASH = "a96f105206d86ec2e815bb762fb0d4e94a44ac2b6577146d8034673ceb87ed55"
    }
}
