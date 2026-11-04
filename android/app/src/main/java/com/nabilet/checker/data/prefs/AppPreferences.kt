package com.nabilet.checker.data.prefs

import android.content.Context
import com.nabilet.checker.domain.model.CheckerConfig

/**
 * SharedPreferences is enough here: everything stored is non-secret. The one
 * secret the app owns — the device token — is handled by TokenVault and never
 * touches this file.
 */
class AppPreferences(context: Context) {

    private val prefs = context.applicationContext
        .getSharedPreferences(FILE, Context.MODE_PRIVATE)

    var baseUrl: String
        get() = prefs.getString(KEY_BASE_URL, "").orEmpty()
        set(value) = prefs.edit().putString(KEY_BASE_URL, value).apply()

    var bundleId: String?
        get() = prefs.getString(KEY_BUNDLE_ID, null)
        set(value) = prefs.edit().putString(KEY_BUNDLE_ID, value).apply()

    var sessionReference: String?
        get() = prefs.getString(KEY_SESSION, null)
        set(value) = prefs.edit().putString(KEY_SESSION, value).apply()

    var devicePublicId: String?
        get() = prefs.getString(KEY_DEVICE, null)
        set(value) = prefs.edit().putString(KEY_DEVICE, value).apply()

    var eventPublicId: String?
        get() = prefs.getString(KEY_EVENT, null)
        set(value) = prefs.edit().putString(KEY_EVENT, value).apply()

    var setupComplete: Boolean
        get() = prefs.getBoolean(KEY_SETUP, false)
        set(value) = prefs.edit().putBoolean(KEY_SETUP, value).apply()

    var shiftStartedAt: Long
        get() = prefs.getLong(KEY_SHIFT_START, 0L)
        set(value) = prefs.edit().putLong(KEY_SHIFT_START, value).apply()

    var lastSyncAt: Long
        get() = prefs.getLong(KEY_LAST_SYNC, 0L)
        set(value) = prefs.edit().putLong(KEY_LAST_SYNC, value).apply()

    fun readConfig(hasToken: Boolean): CheckerConfig = CheckerConfig(
        baseUrl = baseUrl,
        hasToken = hasToken,
        bundleId = bundleId,
        sessionReference = sessionReference,
        devicePublicId = devicePublicId,
        eventPublicId = eventPublicId,
        setupComplete = setupComplete,
        shiftStartedAt = shiftStartedAt,
        lastSyncAt = lastSyncAt.takeIf { it > 0L },
    )

    /** Everything that belongs to one server/device identity. */
    fun clearIdentity() {
        prefs.edit()
            .remove(KEY_BUNDLE_ID)
            .remove(KEY_SESSION)
            .remove(KEY_DEVICE)
            .remove(KEY_EVENT)
            .remove(KEY_SETUP)
            .remove(KEY_SHIFT_START)
            .remove(KEY_LAST_SYNC)
            .apply()
    }

    fun clearAll() {
        prefs.edit().clear().apply()
    }

    private companion object {
        const val FILE = "nabilet_checker_prefs"
        const val KEY_BASE_URL = "base_url"
        const val KEY_BUNDLE_ID = "bundle_id"
        const val KEY_SESSION = "session_reference"
        const val KEY_DEVICE = "device_public_id"
        const val KEY_EVENT = "event_public_id"
        const val KEY_SETUP = "setup_complete"
        const val KEY_SHIFT_START = "shift_started_at"
        const val KEY_LAST_SYNC = "last_sync_at"
    }
}
