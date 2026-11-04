package com.nabilet.checker.ui.components

import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource
import com.nabilet.checker.R
import com.nabilet.checker.domain.model.CheckinResult
import com.nabilet.checker.domain.model.ScanSource

/** Maps the wire verdict to the Russian label shown to the operator. */
fun resultStringRes(result: CheckinResult): Int = when (result) {
    CheckinResult.VALID -> R.string.result_valid
    CheckinResult.ALREADY_USED -> R.string.result_already_used
    CheckinResult.CANCELLED -> R.string.result_cancelled
    CheckinResult.REFUNDED -> R.string.result_refunded
    CheckinResult.EXPIRED -> R.string.result_expired
    CheckinResult.INVALID_SIGNATURE -> R.string.result_invalid_signature
    CheckinResult.WRONG_SESSION -> R.string.result_wrong_session
    CheckinResult.WRONG_EVENT -> R.string.result_wrong_event
    CheckinResult.UNKNOWN_TICKET -> R.string.result_unknown_ticket
    CheckinResult.CONFLICT -> R.string.result_conflict
}

fun sourceStringRes(source: ScanSource): Int = when (source) {
    ScanSource.ONLINE -> R.string.source_online
    ScanSource.OFFLINE -> R.string.source_offline
    ScanSource.LOCAL -> R.string.source_local
}

@Composable
fun resultLabel(result: CheckinResult): String = stringResource(resultStringRes(result))

@Composable
fun sourceLabel(source: ScanSource): String = stringResource(sourceStringRes(source))
