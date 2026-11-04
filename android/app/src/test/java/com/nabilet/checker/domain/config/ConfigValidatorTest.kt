package com.nabilet.checker.domain.config

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class ConfigValidatorTest {

    @Test
    fun `keeps a plain https origin`() {
        assertEquals(
            "https://tickets.example.com",
            ok(ConfigValidator.normalizeBaseUrl("https://tickets.example.com", false)),
        )
    }

    @Test
    fun `lowercases the host and keeps the port`() {
        assertEquals(
            "https://tickets.example.com:8443",
            ok(ConfigValidator.normalizeBaseUrl("https://Tickets.Example.COM:8443", false)),
        )
    }

    @Test
    fun `trims whitespace and drops a single trailing slash`() {
        assertEquals(
            "https://tickets.example.com",
            ok(ConfigValidator.normalizeBaseUrl("  https://tickets.example.com/  ", false)),
        )
    }

    @Test
    fun `allows http only when cleartext is enabled`() {
        assertTrue(
            ConfigValidator.normalizeBaseUrl("http://10.0.2.2:8080", false) is ValidationResult.Error,
        )
        assertEquals(
            "http://10.0.2.2:8080",
            ok(ConfigValidator.normalizeBaseUrl("http://10.0.2.2:8080", true)),
        )
    }

    @Test
    fun `rejects an empty address`() {
        assertTrue(ConfigValidator.normalizeBaseUrl("", true) is ValidationResult.Error)
        assertTrue(ConfigValidator.normalizeBaseUrl("   ", true) is ValidationResult.Error)
    }

    @Test
    fun `rejects an address without a scheme`() {
        assertTrue(
            ConfigValidator.normalizeBaseUrl("tickets.example.com", true) is ValidationResult.Error,
        )
    }

    @Test
    fun `rejects an unsupported scheme`() {
        assertTrue(
            ConfigValidator.normalizeBaseUrl("ftp://tickets.example.com", true) is ValidationResult.Error,
        )
    }

    @Test
    fun `rejects a path because it silently changes the request target`() {
        assertTrue(
            ConfigValidator.normalizeBaseUrl("https://tickets.example.com/api", false) is ValidationResult.Error,
        )
    }

    @Test
    fun `rejects a query fragment and user info`() {
        assertTrue(
            ConfigValidator.normalizeBaseUrl("https://tickets.example.com?x=1", false) is ValidationResult.Error,
        )
        assertTrue(
            ConfigValidator.normalizeBaseUrl("https://tickets.example.com#top", false) is ValidationResult.Error,
        )
        assertTrue(
            ConfigValidator.normalizeBaseUrl("https://user:pass@tickets.example.com", false) is ValidationResult.Error,
        )
    }

    @Test
    fun `accepts the device token shape`() {
        val token = "nbck_" + "a".repeat(32)
        assertEquals(token, ok(ConfigValidator.validateToken(token)))
        assertEquals(
            "nbck_" + "B1".repeat(8),
            ok(ConfigValidator.validateToken("  nbck_" + "B1".repeat(8) + "  ")),
        )
    }

    @Test
    fun `rejects a token with the wrong prefix or alphabet`() {
        assertTrue(ConfigValidator.validateToken("") is ValidationResult.Error)
        assertTrue(ConfigValidator.validateToken("nbck_short") is ValidationResult.Error)
        assertTrue(ConfigValidator.validateToken("bck_" + "a".repeat(32)) is ValidationResult.Error)
        assertTrue(ConfigValidator.validateToken("nbck_" + "a".repeat(31) + "-") is ValidationResult.Error)
        assertTrue(ConfigValidator.validateToken("nbck_" + "a".repeat(129)) is ValidationResult.Error)
    }

    @Test
    fun `accepts internal numbers and public ids as a session reference`() {
        assertEquals("1001", ok(ConfigValidator.validateSessionReference("1001")))
        assertEquals(
            "01JSESSION000000000000000",
            ok(ConfigValidator.validateSessionReference(" 01JSESSION000000000000000 ")),
        )
    }

    @Test
    fun `rejects a session reference with separators`() {
        assertTrue(ConfigValidator.validateSessionReference("") is ValidationResult.Error)
        assertTrue(ConfigValidator.validateSessionReference("1001/2") is ValidationResult.Error)
        assertTrue(ConfigValidator.validateSessionReference("a".repeat(65)) is ValidationResult.Error)
    }

    @Test
    fun `accepts and rejects bundle references with the same rules`() {
        assertEquals("01JBUNDLE0000000000000000", ok(ConfigValidator.validateBundleReference("01JBUNDLE0000000000000000")))
        assertTrue(ConfigValidator.validateBundleReference("") is ValidationResult.Error)
        assertTrue(ConfigValidator.validateBundleReference("../../etc/passwd") is ValidationResult.Error)
    }

    private fun ok(result: ValidationResult<String>): String = when (result) {
        is ValidationResult.Ok<String> -> result.value
        is ValidationResult.Error -> throw AssertionError("expected Ok, got ${result.message}")
    }
}
