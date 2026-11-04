package com.nabilet.checker.data.repository

import com.nabilet.checker.domain.model.ActiveBundle
import com.nabilet.checker.domain.model.CheckerConfig
import com.nabilet.checker.domain.model.ScanRecord
import com.nabilet.checker.domain.model.SessionStats

enum class TokenState {
    UNKNOWN,
    CHECKING,
    VALID,
    INVALID,
}

data class CheckerState(
    val initialized: Boolean = false,
    val config: CheckerConfig = CheckerConfig(),
    val bundle: ActiveBundle? = null,
    val recentScans: List<ScanRecord> = emptyList(),
    val stats: SessionStats = SessionStats(),
    val syncing: Boolean = false,
    val tokenState: TokenState = TokenState.UNKNOWN,
)

enum class SyncOutcomeKind {
    DONE,
    AUTH,
    RETRY,
    FAILED,
}

data class SyncOutcome(
    val kind: SyncOutcomeKind,
    val message: String? = null,
    val sent: Int = 0,
)
