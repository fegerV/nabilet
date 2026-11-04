package com.nabilet.checker.domain.model

/**
 * A parsed NABILET QR payload.
 *
 * Wire format (see `App\Core\Support\QrSigner`):
 *
 *     NB1.<ticketId>.<randomToken>.<signature>
 *
 * The payload carries no personal data by design. The signature is an
 * HMAC-SHA256, but the app has no secret to check it with — only the server
 * does. The scanner therefore treats the payload as an identifier and lets the
 * server (online) or the offline bundle (offline) decide. See docs/DESIGN.md.
 */
data class QrPayload(
    val raw: String,
    val version: String,
    val ticketId: String,
    val token: String,
    val signature: String,
)

enum class QrParseError {
    EMPTY,
    UNSUPPORTED_VERSION,
    WRONG_SEGMENT_COUNT,
    BAD_TICKET_ID,
    BAD_TOKEN,
    BAD_SIGNATURE,
}

sealed interface QrParseResult {
    data class Valid(val payload: QrPayload) : QrParseResult
    data class Invalid(val error: QrParseError, val detail: String) : QrParseResult
}

object QrPayloadParser {

    private const val VERSION = "NB1"

    private val TICKET_ID = Regex("^[A-Za-z0-9_-]{1,64}$")
    private val TOKEN = Regex("^[A-Za-z0-9_-]{8,128}$")
    private val SIGNATURE = Regex("^[A-Za-z0-9_-]{16,128}$")

    fun parse(raw: String): QrParseResult {
        val text = raw.trim()
        if (text.isEmpty()) {
            return QrParseResult.Invalid(QrParseError.EMPTY, "payload is empty")
        }

        val parts = text.split('.')
        if (parts.size != 4) {
            return QrParseResult.Invalid(
                QrParseError.WRONG_SEGMENT_COUNT,
                "expected 4 segments, got ${parts.size}",
            )
        }

        if (parts[0] != VERSION) {
            return QrParseResult.Invalid(
                QrParseError.UNSUPPORTED_VERSION,
                "unsupported version '${parts[0]}'",
            )
        }

        if (!TICKET_ID.matches(parts[1])) {
            return QrParseResult.Invalid(QrParseError.BAD_TICKET_ID, "ticket id is malformed")
        }

        if (!TOKEN.matches(parts[2])) {
            return QrParseResult.Invalid(QrParseError.BAD_TOKEN, "token is malformed")
        }

        if (!SIGNATURE.matches(parts[3])) {
            return QrParseResult.Invalid(QrParseError.BAD_SIGNATURE, "signature is malformed")
        }

        return QrParseResult.Valid(
            QrPayload(
                raw = text,
                version = parts[0],
                ticketId = parts[1],
                token = parts[2],
                signature = parts[3],
            ),
        )
    }
}
