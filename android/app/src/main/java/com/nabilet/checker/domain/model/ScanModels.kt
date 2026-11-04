package com.nabilet.checker.domain.model

enum class ScanSource(val wire: String) {
    ONLINE("online"),
    OFFLINE("offline"),
    LOCAL("local"),
}

/**
 * One scan as it is stored locally. `clientScanId` is generated before the
 * network call, so a retry is idempotent on the server
 * (`uq_ticket_scans_client(device_id, client_scan_id)`).
 */
data class ScanRecord(
    val clientScanId: String,
    val ticketId: String,
    val ticketNumber: String?,
    val result: CheckinResult,
    val source: ScanSource,
    val sessionId: String?,
    val deviceId: String?,
    val scannedAt: Long,
    val synced: Boolean,
    val serverMessage: String?,
    val note: String?,
)

data class SessionStats(
    val total: Int = 0,
    val admitted: Int = 0,
    val denied: Int = 0,
    val pending: Int = 0,
    val lastScanAt: Long? = null,
    val lastFiveMinutes: Int = 0,
    val byResult: Map<CheckinResult, Int> = emptyMap(),
)

data class ScanFeedback(
    val id: Long,
    val result: CheckinResult,
    val ticketId: String?,
    val ticketNumber: String?,
    val serverMessage: String?,
    val note: String?,
    val source: ScanSource,
    val scannedAt: Long,
    val queued: Boolean,
)

enum class BlockedReason {
    NO_SESSION,
    NO_BUNDLE,
    BUNDLE_EXPIRED,
    TOKEN_REJECTED,
    NETWORK,
    NOT_CONFIGURED,
    INTERNAL,
    BUSY,
}

sealed interface ScanSubmission {
    data class Feedback(val feedback: ScanFeedback) : ScanSubmission
    data class Blocked(val reason: BlockedReason, val message: String) : ScanSubmission
}

data class SyncItemResult(
    val clientScanId: String,
    val result: CheckinResult?,
    val message: String?,
)

data class SyncReport(
    val sent: Int = 0,
    val accepted: Int = 0,
    val duplicates: Int = 0,
    val conflicts: Int = 0,
    val rejected: Int = 0,
    val items: List<SyncItemResult> = emptyList(),
)
