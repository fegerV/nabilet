package com.nabilet.checker.domain.bundle

import com.nabilet.checker.domain.model.ActiveBundle
import com.nabilet.checker.domain.model.BundleTicketEntry
import com.nabilet.checker.domain.model.BundleTicketSection
import com.nabilet.checker.domain.model.CheckinResult

enum class OfflineUnavailable {
    EXPIRED_BUNDLE,
    NO_SESSION,
}

sealed interface OfflineDecision {
    data class Verdict(
        val result: CheckinResult,
        val ticketNumber: String?,
        /** Why the local decision was made; mapped to a user string by the repository. */
        val reason: OfflineReason,
    ) : OfflineDecision

    data class Unavailable(
        val reason: OfflineUnavailable,
        val detail: String,
    ) : OfflineDecision
}

enum class OfflineReason {
    VALID_FROM_BUNDLE,
    ALREADY_ADMITTED_LOCALLY,
    REVOKED_STATUS,
    UNKNOWN_TICKET,
}

/**
 * Decides admission without a network using only the downloaded bundle.
 *
 * The bundle is authoritative for the session it covers. A ticket that is in
 * `valid` with status `issued` is admitted; a ticket in `revoked` is refused
 * with the status-derived verdict; an unknown ticket is refused, not admitted
 * (fail-closed). Expiry is checked before anything else: an expired bundle is
 * not an admission authority.
 *
 * The lookup is a function so unit tests do not need SQLite and the repository
 * can use an indexed query instead of keeping 30 000 tickets in memory.
 */
object OfflineVerifier {

    fun decide(
        bundle: ActiveBundle,
        ticketId: String,
        nowMillis: Long,
        locallyAdmitted: Boolean,
        lookup: (String) -> BundleTicketEntry?,
    ): OfflineDecision {
        if (bundle.isExpired(nowMillis)) {
            return OfflineDecision.Unavailable(
                OfflineUnavailable.EXPIRED_BUNDLE,
                "bundle expired at ${bundle.expiresAt}",
            )
        }

        if (bundle.sessionInternalId == null) {
            // An event-wide bundle has no single session id, and the sync
            // endpoint requires one. Accepting scans here would produce work
            // that cannot be reported back, so refuse early and explain.
            return OfflineDecision.Unavailable(
                OfflineUnavailable.NO_SESSION,
                "bundle is event-wide and cannot be synced by session",
            )
        }

        if (locallyAdmitted) {
            return OfflineDecision.Verdict(
                result = CheckinResult.ALREADY_USED,
                ticketNumber = lookup(ticketId)?.number,
                reason = OfflineReason.ALREADY_ADMITTED_LOCALLY,
            )
        }

        val ticket = lookup(ticketId)
            ?: return OfflineDecision.Verdict(
                result = CheckinResult.UNKNOWN_TICKET,
                ticketNumber = null,
                reason = OfflineReason.UNKNOWN_TICKET,
            )

        if (ticket.section == BundleTicketSection.VALID && ticket.status == "issued") {
            return OfflineDecision.Verdict(
                result = CheckinResult.VALID,
                ticketNumber = ticket.number,
                reason = OfflineReason.VALID_FROM_BUNDLE,
            )
        }

        if (ticket.section == BundleTicketSection.REVOKED && ticket.status == "issued") {
            // The server never builds this pair (the revoked list is filtered to
            // used, revoked, refunded and cancelled), but if it ever did, status
            // alone would admit a ticket the server deliberately marked. Section
            // wins: fail closed.
            return OfflineDecision.Verdict(
                result = CheckinResult.CONFLICT,
                ticketNumber = ticket.number,
                reason = OfflineReason.REVOKED_STATUS,
            )
        }

        return OfflineDecision.Verdict(
            result = resultForStatus(ticket.status),
            ticketNumber = ticket.number,
            reason = OfflineReason.REVOKED_STATUS,
        )
    }

    fun resultForStatus(status: String): CheckinResult = when (status) {
        "used" -> CheckinResult.ALREADY_USED
        "revoked" -> CheckinResult.CONFLICT
        "refunded" -> CheckinResult.REFUNDED
        "cancelled" -> CheckinResult.CANCELLED
        "expired" -> CheckinResult.EXPIRED
        "issued" -> CheckinResult.VALID
        else -> CheckinResult.CONFLICT
    }
}
