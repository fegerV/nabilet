package com.nabilet.checker.domain.util

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class CryptoUtilsTest {

    @Test
    fun `hashes the empty string`() {
        assertEquals(
            "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
            CryptoUtils.sha256Hex(""),
        )
    }

    @Test
    fun `hashes ascii the same way every sha-256 implementation does`() {
        assertEquals(
            "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
            CryptoUtils.sha256Hex("abc"),
        )
    }

    @Test
    fun `hashes utf-8 bytes rather than utf-16 code units`() {
        // "Привет" in UTF-8. The digest was produced with PHP's
        // hash('sha256', 'Привет'), i.e. the server's implementation, so this
        // test pins the app to the same byte interpretation.
        assertEquals(
            "dd679c0b9fd408a04148aa7d30c9df393f67b7227f65693fffe0ed6d0f0ade59",
            CryptoUtils.sha256Hex("Привет"),
        )
    }

    @Test
    fun `byte and string overloads agree`() {
        val text = "ticket/42"
        assertEquals(
            CryptoUtils.sha256Hex(text),
            CryptoUtils.sha256Hex(text.toByteArray(Charsets.UTF_8)),
        )
    }

    @Test
    fun `compares equal digests`() {
        val digest = CryptoUtils.sha256Hex("abc")
        assertTrue(CryptoUtils.constantTimeEquals(digest, digest))
        assertTrue(CryptoUtils.constantTimeEquals("", ""))
    }

    @Test
    fun `rejects a one-character difference and equal prefixes`() {
        assertFalse(
            CryptoUtils.constantTimeEquals(
                "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
                "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ae",
            ),
        )
        // Differing lengths must not throw or report equality.
        assertFalse(
            CryptoUtils.constantTimeEquals(
                "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad",
                "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015a",
            ),
        )
    }

    @Test
    fun `decodes url-safe base64 without padding`() {
        assertEquals("hello", String(CryptoUtils.base64UrlDecode("aGVsbG8")!!, Charsets.UTF_8))
        assertEquals("hello", String(CryptoUtils.base64UrlDecode("aGVsbG8=")!!, Charsets.UTF_8))
    }

    @Test
    fun `returns null for text that is not base64`() {
        assertNull(CryptoUtils.base64UrlDecode("!!!"))
        assertNull(CryptoUtils.base64UrlDecode("a"))
    }

    @Test
    fun `produces rfc 4231 hmac vectors`() {
        // RFC 4231, test case 2 ("Jefe" / "what do ya want for nothing?"),
        // base64url-encoded without padding. The server's QrSigner uses the
        // same encoding.
        assertEquals(
            "W9zBRr9gdU5qBCQmCJV1x1oAPwidJzmDnexYuWTsOEM",
            CryptoUtils.hmacSha256Base64Url(
                "what do ya want for nothing?",
                "Jefe".toByteArray(Charsets.UTF_8),
            ),
        )
        // An empty key is a legal HmacSHA256 input on the JVM; verify it produces
        // a stable value instead of throwing.
        assertEquals(
            "BSuRZ-TUe4hlEYJYOJEjd2N8QxF7CJCRGgkR5c6Tuvc",
            CryptoUtils.hmacSha256Base64Url("key", ByteArray(0)),
        )
    }
}
