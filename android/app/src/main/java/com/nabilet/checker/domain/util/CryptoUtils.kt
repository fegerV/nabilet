package com.nabilet.checker.domain.util

import java.security.MessageDigest
import java.util.Base64
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * Cryptographic helpers that are deliberately free of `android.*` imports.
 *
 * Why: the same code runs in JVM unit tests and on the device, so the hash and
 * the signature rules cannot silently diverge between the two. `minSdk` is 26,
 * which is where `java.util.Base64` became available.
 */
object CryptoUtils {

    private const val HMAC_ALGORITHM = "HmacSHA256"

    fun sha256Hex(input: String): String = sha256Hex(input.toByteArray(Charsets.UTF_8))

    fun sha256Hex(bytes: ByteArray): String {
        val digest = MessageDigest.getInstance("SHA-256").digest(bytes)
        val out = StringBuilder(digest.size * 2)
        for (byte in digest) {
            val value = byte.toInt() and 0xFF
            out.append(HEX[value ushr 4])
            out.append(HEX[value and 0x0F])
        }
        return out.toString()
    }

    /**
     * Compares two hex digests without leaking the position of the first
     * mismatch through timing. Both inputs are expected to be lowercase hex.
     */
    fun constantTimeEquals(a: String, b: String): Boolean {
        if (a.length != b.length) return false
        var diff = 0
        for (index in a.indices) {
            diff = diff or (a[index].code xor b[index].code)
        }
        return diff == 0
    }

    fun base64UrlDecode(value: String): ByteArray? =
        runCatching { Base64.getUrlDecoder().decode(value) }.getOrNull()

    /**
     * Mirror of `App\Core\Support\QrSigner` for a future bundle format that
     * carries the QR signing secret or a public-key signature. The current
     * backend bundle does not include either, so this is not used for
     * admission decisions yet — see docs/DESIGN.md, "What the bundle does not
     * prove".
     */
    fun hmacSha256Base64Url(data: String, key: ByteArray): String {
        val mac = Mac.getInstance(HMAC_ALGORITHM)
        mac.init(SecretKeySpec(key, HMAC_ALGORITHM))
        val raw = mac.doFinal(data.toByteArray(Charsets.UTF_8))
        return Base64.getUrlEncoder().withoutPadding().encodeToString(raw)
    }

    private val HEX = "0123456789abcdef".toCharArray()
}
