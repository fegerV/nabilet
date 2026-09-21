package com.nabilet.checker.domain.util

import android.util.Base64
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * CryptoUtils - Cryptographic operations for ticket verification
 * 
 * Provides HMAC-SHA256 signature verification for QR codes.
 * Uses constant-time comparison to prevent timing attacks.
 */
object CryptoUtils {

    private const val HMAC_ALGORITHM = "HmacSHA256"
    private const val ENCODING = Charsets.UTF_8

    /**
     * Verify HMAC-SHA256 signature of a message
     */
    fun verifySignature(data: String, signature: String, secretKey: ByteArray): Boolean {
        return try {
            val expectedSignature = generateHMAC(data, secretKey)
            secureCompare(signature.decodeBase64(), expectedSignature)
        } catch (e: Exception) {
            false
        }
    }

    /**
     * Generate HMAC-SHA256 signature for data
     */
    fun generateHMAC(data: String, secretKey: ByteArray): String {
        val mac = Mac.getInstance(HMAC_ALGORITHM)
        val keySpec = SecretKeySpec(secretKey, HMAC_ALGORITHM)
        mac.init(keySpec)
        
        val hmacBytes = mac.doFinal(data.toByteArray(ENCODING))
        return Base64.encodeToString(hmacBytes, Base64.NO_WRAP)
    }

    /**
     * Constant-time byte array comparison to prevent timing attacks
     */
    fun secureCompare(a: ByteArray, b: ByteArray): Boolean {
        if (a.size != b.size) return false
        
        var result = 0
        for (i in a.indices) {
            result = result or (a[i].toInt() xor b[i].toInt())
        }
        
        return result == 0
    }

    /**
     * Generate SHA-256 hash of input data
     */
    fun sha256(input: String): String {
        val md = java.security.MessageDigest.getInstance("SHA-256")
        val digest = md.digest(input.toByteArray(ENCODING))
        return digest.joinToString("") { "%02x".format(it) }
    }

    /**
     * Validate public key fingerprint format
     */
    fun isValidFingerprint(fingerprint: String): Boolean {
        return fingerprint.matches(Regex("^[0-9a-fA-F]{64}$")) ||
               fingerprint.matches(Regex("^([0-9a-fA-F]{2}:){31}[0-9a-fA-F]{2}$"))
    }

    /**
     * Format fingerprint for display (colon-separated)
     */
    fun formatFingerprint(fingerprint: String): String {
        val cleanHex = fingerprint.replace(":", "")
        if (cleanHex.length != 64) return fingerprint
        return cleanHex.chunked(2).joinToString(":") { it.uppercase() }
    }
}

private fun String.decodeBase64(): ByteArray = Base64.decode(this, Base64.NO_WRAP)
fun ByteArray.encodeBase64(): String = Base64.encodeToString(this, Base64.NO_WRAP)
