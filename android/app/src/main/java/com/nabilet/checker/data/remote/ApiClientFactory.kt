package com.nabilet.checker.data.remote

import com.nabilet.checker.domain.model.ServerBundle
import org.json.JSONObject
import java.util.concurrent.TimeUnit
import okhttp3.OkHttpClient

object ApiClientFactory {
    fun create(): OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()
}

/**
 * Maps `data` from `GET /api/v1/offline-bundles/{id}` to the envelope the
 * parser expects. The server returns `payload_json` as a JSON object (the model
 * casts it to array); accept a JSON string too, so a future transport change
 * does not silently break the hash check.
 */
object BundleMapper {

    fun toServerBundle(data: JSONObject): ServerBundle? {
        val id = data.optString("id", "")
        if (id.isBlank()) return null

        val hash = data.optString("bundle_hash", "")
        if (hash.isBlank()) return null

        if (!data.has("schema_version")) return null
        val schemaVersion = data.optInt("schema_version", -1)
        if (schemaVersion < 1) return null

        if (!data.has("ticket_count") || !data.has("revoked_count")) return null

        val payload = when (val value = data.opt("payload_json")) {
            is JSONObject -> value.toString()
            is String -> value
            else -> return null
        }

        val keyFingerprint = if (data.isNull("public_key_fingerprint")) {
            null
        } else {
            data.optString("public_key_fingerprint").takeIf { it.isNotBlank() }
        }

        return ServerBundle(
            id = id,
            hash = hash,
            schemaVersion = schemaVersion,
            keyFingerprint = keyFingerprint,
            eventPublicId = data.optString("event_id", ""),
            sessionPublicId = if (data.isNull("session_id")) null else data.optString("session_id", ""),
            ticketCount = data.optInt("ticket_count", -1),
            revokedCount = data.optInt("revoked_count", -1),
            expiresAt = if (data.isNull("expires_at")) null else data.optString("expires_at", ""),
            generatedAt = if (data.isNull("generated_at")) null else data.optString("generated_at", ""),
            status = data.optString("status", ""),
            payloadJson = payload,
        )
    }
}
