package com.nabilet.checker.data.security

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Stores the `nbck_…` device token encrypted with a key that never leaves the
 * Android Keystore.
 *
 * Failure policy: if encryption is not possible, [save] returns `false` and the
 * token is NOT written in plaintext. An operator can still use the app but has
 * to enter the token again next launch — losing a shift is preferable to
 * leaving a server credential readable by any process with access to app data.
 *
 * No biometric prompt on purpose: a door operator must be able to start scanning
 * in seconds, often with gloves, and the token grants only checker endpoints.
 */
class TokenVault(context: Context) {

    private val prefs = context.applicationContext
        .getSharedPreferences(FILE, Context.MODE_PRIVATE)

    fun load(): String? {
        val encoded = prefs.getString(KEY_BLOB, null) ?: return null
        return try {
            val blob = Base64.decode(encoded, Base64.NO_WRAP)
            if (blob.size < 3) return null
            val ivLength = blob[0].toInt() and 0xFF
            if (ivLength <= 0 || blob.size <= ivLength + 2) return null
            val iv = blob.copyOfRange(1, 1 + ivLength)
            val ciphertext = blob.copyOfRange(1 + ivLength, blob.size)
            val cipher = Cipher.getInstance(TRANSFORMATION)
            cipher.init(Cipher.DECRYPT_MODE, secretKey(), GCMParameterSpec(TAG_BITS, iv))
            String(cipher.doFinal(ciphertext), Charsets.UTF_8)
        } catch (_: Exception) {
            // A key can disappear after a device restore or an OS upgrade.
            // Fail closed and ask for the token again.
            prefs.edit().remove(KEY_BLOB).apply()
            null
        }
    }

    fun save(token: String): Boolean {
        if (token.isBlank()) return false
        return try {
            val cipher = Cipher.getInstance(TRANSFORMATION)
            cipher.init(Cipher.ENCRYPT_MODE, secretKey())
            val iv = cipher.iv
            val ciphertext = cipher.doFinal(token.toByteArray(Charsets.UTF_8))
            val blob = ByteArray(1 + iv.size + ciphertext.size)
            blob[0] = iv.size.toByte()
            System.arraycopy(iv, 0, blob, 1, iv.size)
            System.arraycopy(ciphertext, 0, blob, 1 + iv.size, ciphertext.size)
            prefs.edit()
                .putString(KEY_BLOB, Base64.encodeToString(blob, Base64.NO_WRAP))
                .commit()
        } catch (_: Exception) {
            false
        }
    }

    fun clear() {
        prefs.edit().remove(KEY_BLOB).apply()
    }

    private fun secretKey(): SecretKey {
        val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
        (keyStore.getEntry(KEY_ALIAS, null) as? KeyStore.SecretKeyEntry)
            ?.let { return it.secretKey }

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE)
        generator.init(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256)
                .setRandomizedEncryptionRequired(true)
                .build(),
        )
        return generator.generateKey()
    }

    private companion object {
        const val FILE = "nabilet_checker_secure"
        const val KEY_BLOB = "device_token_blob"
        const val KEY_ALIAS = "nabilet.checker.device_token.v1"
        const val ANDROID_KEYSTORE = "AndroidKeyStore"
        const val TRANSFORMATION = "AES/GCM/NoPadding"
        const val TAG_BITS = 128
    }
}
