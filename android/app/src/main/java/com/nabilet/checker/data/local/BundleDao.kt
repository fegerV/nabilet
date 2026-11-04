package com.nabilet.checker.data.local

import android.content.ContentValues
import android.database.Cursor
import com.nabilet.checker.domain.model.ActiveBundle
import com.nabilet.checker.domain.model.BundleTicketEntry
import com.nabilet.checker.domain.model.BundleTicketSection
import com.nabilet.checker.domain.model.ParsedBundle
import com.nabilet.checker.domain.util.CryptoUtils

/**
 * Persists the active bundle. The canonical JSON is stored as-is so the hash can
 * be re-verified on every load without parsing the ticket arrays again; tickets
 * live in their own table and are looked up by `(bundle_id, ticket_id)`.
 */
class BundleDao(private val helper: CheckerDatabase) {

    fun save(parsed: ParsedBundle) {
        val db = helper.writableDatabase
        db.beginTransaction()
        try {
            val bundle = parsed.bundle
            val values = ContentValues().apply {
                put("id", bundle.id)
                put("bundle_hash", bundle.hash)
                put("schema_version", bundle.schemaVersion)
                put("key_fingerprint", bundle.keyFingerprint)
                put("device_public_id", bundle.devicePublicId)
                put("org_internal_id", bundle.orgInternalId)
                put("event_internal_id", bundle.eventInternalId)
                put("session_internal_id", bundle.sessionInternalId)
                put("event_public_id", bundle.eventPublicId)
                put("session_public_id", bundle.sessionPublicId)
                put("ticket_count", bundle.ticketCount)
                put("revoked_count", bundle.revokedCount)
                put("expires_at", bundle.expiresAt)
                put("generated_at", bundle.generatedAt)
                put("downloaded_at", bundle.downloadedAt)
                put("canonical_json", parsed.canonicalJson)
                put("status", bundle.status)
            }
            db.insertWithOnConflict("bundles", null, values, android.database.sqlite.SQLiteDatabase.CONFLICT_REPLACE)
            db.delete("bundle_tickets", "bundle_id = ?", arrayOf(bundle.id))

            for (ticket in parsed.tickets) {
                val ticketValues = ContentValues().apply {
                    put("bundle_id", bundle.id)
                    put("ticket_id", ticket.ticketId)
                    put("ticket_number", ticket.number)
                    put("status", ticket.status)
                    put("section", ticket.section.name)
                }
                db.insertWithOnConflict(
                    "bundle_tickets",
                    null,
                    ticketValues,
                    android.database.sqlite.SQLiteDatabase.CONFLICT_REPLACE,
                )
            }

            // Keep the last three bundles: enough to switch back after a
            // mistaken download, not enough to fill the phone.
            db.execSQL(
                "DELETE FROM bundles WHERE id NOT IN " +
                    "(SELECT id FROM bundles ORDER BY downloaded_at DESC LIMIT 3)",
            )
            db.execSQL("DELETE FROM bundle_tickets WHERE bundle_id NOT IN (SELECT id FROM bundles)")
            db.setTransactionSuccessful()
        } finally {
            db.endTransaction()
        }
    }

    fun load(id: String): ActiveBundle? {
        var canonical: String? = null
        val bundle = helper.readableDatabase.query(
            "bundles",
            null,
            "id = ?",
            arrayOf(id),
            null,
            null,
            null,
            "1",
        ).use { cursor ->
            if (cursor.moveToFirst()) {
                canonical = cursor.getString(cursor.getColumnIndexOrThrow("canonical_json"))
                cursor.toBundle()
            } else {
                null
            }
        } ?: return null

        val bytes = canonical ?: return null
        if (!CryptoUtils.constantTimeEquals(CryptoUtils.sha256Hex(bytes), bundle.hash)) {
            delete(id)
            return null
        }
        return bundle
    }

    fun latest(): ActiveBundle? {
        val id = helper.readableDatabase.rawQuery(
            "SELECT id FROM bundles ORDER BY downloaded_at DESC LIMIT 1",
            null,
        ).use { cursor -> if (cursor.moveToFirst()) cursor.getString(0) else null } ?: return null
        return load(id)
    }

    fun findTicket(bundleId: String, ticketId: String): BundleTicketEntry? =
        helper.readableDatabase.query(
            "bundle_tickets",
            arrayOf("ticket_id", "ticket_number", "status", "section"),
            "bundle_id = ? AND ticket_id = ?",
            arrayOf(bundleId, ticketId),
            null,
            null,
            null,
            "1",
        ).use { cursor ->
            if (!cursor.moveToFirst()) return@use null
            BundleTicketEntry(
                ticketId = cursor.getString(0),
                number = cursor.getString(1),
                status = cursor.getString(2),
                section = runCatching { BundleTicketSection.valueOf(cursor.getString(3)) }
                    .getOrDefault(BundleTicketSection.REVOKED),
            )
        }

    fun delete(id: String) {
        val db = helper.writableDatabase
        db.beginTransaction()
        try {
            db.delete("bundle_tickets", "bundle_id = ?", arrayOf(id))
            db.delete("bundles", "id = ?", arrayOf(id))
            db.setTransactionSuccessful()
        } finally {
            db.endTransaction()
        }
    }

    fun deleteAll() {
        val db = helper.writableDatabase
        db.beginTransaction()
        try {
            db.delete("bundle_tickets", null, null)
            db.delete("bundles", null, null)
            db.setTransactionSuccessful()
        } finally {
            db.endTransaction()
        }
    }

    private fun Cursor.toBundle(): ActiveBundle {
        fun nullableLong(column: String): Long? {
            val index = getColumnIndexOrThrow(column)
            return if (isNull(index)) null else getLong(index)
        }

        fun nullableString(column: String): String? {
            val index = getColumnIndexOrThrow(column)
            return if (isNull(index)) null else getString(index)
        }

        return ActiveBundle(
            id = getString(getColumnIndexOrThrow("id")),
            hash = getString(getColumnIndexOrThrow("bundle_hash")),
            schemaVersion = getInt(getColumnIndexOrThrow("schema_version")),
            keyFingerprint = getString(getColumnIndexOrThrow("key_fingerprint")),
            devicePublicId = getString(getColumnIndexOrThrow("device_public_id")),
            orgInternalId = getLong(getColumnIndexOrThrow("org_internal_id")),
            eventInternalId = getLong(getColumnIndexOrThrow("event_internal_id")),
            sessionInternalId = nullableLong("session_internal_id"),
            eventPublicId = getString(getColumnIndexOrThrow("event_public_id")),
            sessionPublicId = nullableString("session_public_id"),
            ticketCount = getInt(getColumnIndexOrThrow("ticket_count")),
            revokedCount = getInt(getColumnIndexOrThrow("revoked_count")),
            expiresAt = getLong(getColumnIndexOrThrow("expires_at")),
            generatedAt = nullableLong("generated_at"),
            downloadedAt = getLong(getColumnIndexOrThrow("downloaded_at")),
            status = getString(getColumnIndexOrThrow("status")),
        )
    }
}
