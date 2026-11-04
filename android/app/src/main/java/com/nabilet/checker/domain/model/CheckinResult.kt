package com.nabilet.checker.domain.model

/**
 * The ten verdicts of the checker contract (`docs/openapi.yaml` →
 * `CheckinResult.result`). The wire strings are stored verbatim by the backend
 * in `ticket_scans.result`, so they are not renamed here.
 */
enum class CheckinResult(val wire: String, val admits: Boolean) {
    VALID("valid", true),
    ALREADY_USED("already_used", false),
    CANCELLED("cancelled", false),
    REFUNDED("refunded", false),
    EXPIRED("expired", false),
    INVALID_SIGNATURE("invalid_signature", false),
    WRONG_SESSION("wrong_session", false),
    WRONG_EVENT("wrong_event", false),
    UNKNOWN_TICKET("unknown_ticket", false),
    CONFLICT("conflict", false),
    ;

    companion object {
        fun fromWire(value: String?): CheckinResult? =
            values().firstOrNull { it.wire == value }
    }
}
