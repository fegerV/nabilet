package com.nabilet.checker.domain.model

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class QrPayloadParserTest {

    @Test
    fun `parses a well formed payload`() {
        val result = QrPayloadParser.parse(
            "NB1.01JTICKET0000000000000001.abcdefgh.0123456789abcdef",
        )

        assertTrue("expected a valid payload, got $result", result is QrParseResult.Valid)
        val payload = (result as QrParseResult.Valid).payload
        assertEquals("NB1", payload.version)
        assertEquals("01JTICKET0000000000000001", payload.ticketId)
        assertEquals("abcdefgh", payload.token)
        assertEquals("0123456789abcdef", payload.signature)
        assertEquals(
            "NB1.01JTICKET0000000000000001.abcdefgh.0123456789abcdef",
            payload.raw,
        )
    }

    @Test
    fun `trims surrounding whitespace before parsing`() {
        val result = QrPayloadParser.parse(
            "  NB1.01JTICKET0000000000000001.abcdefgh.0123456789abcdef\n",
        )

        assertTrue(result is QrParseResult.Valid)
        assertEquals(
            "NB1.01JTICKET0000000000000001.abcdefgh.0123456789abcdef",
            (result as QrParseResult.Valid).payload.raw,
        )
    }

    @Test
    fun `rejects an empty payload`() {
        assertError(QrPayloadParser.parse(""), QrParseError.EMPTY)
        assertError(QrPayloadParser.parse("    "), QrParseError.EMPTY)
    }

    @Test
    fun `rejects the wrong number of segments`() {
        assertError(
            QrPayloadParser.parse("NB1.01JTICKET0000000000000001.abcdefgh"),
            QrParseError.WRONG_SEGMENT_COUNT,
        )
        assertError(
            QrPayloadParser.parse("NB1.01JTICKET0000000000000001.abcdefgh.0123456789abcdef.extra"),
            QrParseError.WRONG_SEGMENT_COUNT,
        )
    }

    @Test
    fun `rejects an unknown version`() {
        assertError(
            QrPayloadParser.parse("NB2.01JTICKET0000000000000001.abcdefgh.0123456789abcdef"),
            QrParseError.UNSUPPORTED_VERSION,
        )
    }

    @Test
    fun `rejects a malformed ticket id`() {
        assertError(
            QrPayloadParser.parse("NB1.bad id!.abcdefgh.0123456789abcdef"),
            QrParseError.BAD_TICKET_ID,
        )
        assertError(
            QrPayloadParser.parse("NB1. .abcdefgh.0123456789abcdef"),
            QrParseError.BAD_TICKET_ID,
        )
    }

    @Test
    fun `rejects a malformed token`() {
        // Too short, and standard-base64 characters are not accepted.
        assertError(
            QrPayloadParser.parse("NB1.01JTICKET0000000000000001.short.0123456789abcdef"),
            QrParseError.BAD_TOKEN,
        )
        assertError(
            QrPayloadParser.parse("NB1.01JTICKET0000000000000001.ab+cd/ef.0123456789abcdef"),
            QrParseError.BAD_TOKEN,
        )
    }

    @Test
    fun `rejects a malformed signature`() {
        assertError(
            QrPayloadParser.parse("NB1.01JTICKET0000000000000001.abcdefgh.tooshort"),
            QrParseError.BAD_SIGNATURE,
        )
        assertError(
            QrPayloadParser.parse("NB1.01JTICKET0000000000000001.abcdefgh.0123456789abcde="),
            QrParseError.BAD_SIGNATURE,
        )
    }

    @Test
    fun `checks the segment count before the version`() {
        // A truncated payload is reported as a shape error, not as a version
        // mismatch, because the shape is what actually broke.
        assertError(QrPayloadParser.parse("NB2"), QrParseError.WRONG_SEGMENT_COUNT)
    }

    private fun assertError(result: QrParseResult, expected: QrParseError) {
        assertTrue("expected $expected, got $result", result is QrParseResult.Invalid)
        assertEquals(expected, (result as QrParseResult.Invalid).error)
    }
}
