package com.nabilet.checker.domain.model

/**
 * Non-secret configuration. The device token is never part of this object; it
 * lives encrypted in [com.nabilet.checker.data.security.TokenVault] and is only
 * read when a request is made.
 */
data class CheckerConfig(
    val baseUrl: String = "",
    val hasToken: Boolean = false,
    val bundleId: String? = null,
    val sessionReference: String? = null,
    val devicePublicId: String? = null,
    val eventPublicId: String? = null,
    val setupComplete: Boolean = false,
    val shiftStartedAt: Long = 0L,
    val lastSyncAt: Long? = null,
) {
    val canWorkOffline: Boolean
        get() = bundleId != null

    val canWorkOnline: Boolean
        get() = baseUrl.isNotBlank() && hasToken && sessionReference != null
}
