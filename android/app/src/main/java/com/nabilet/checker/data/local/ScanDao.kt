package com.nabilet.checker.data.local

import android.content.ContentValues
import android.database.Cursor
import com.nabilet.checker.domain.model.CheckinResult
import com.nabilet.checker.domain.model.ScanRecord
import com.nabilet.checker.domain.model.ScanSource
import com.nabilet.checker.domain.model.SessionStats
import com.nabilet.checker.domain.model.SyncItemResult

class ScanDao(private val helper: CheckerDatabase) {

    fun insert(record: ScanRecord): Boolean {
        val values = ContentValues().apply {
            put("client_scan_id", record.clientScanId)
            put("ticket_id", record.ticketId)
            put("ticket_number", record.ticketNumber)
            put("result", record.result.wire)
            put("source", record.source.name)
            put("session_id", record.sessionId)
            put("device_id", record.deviceId)
            put("scanned_at", record.scannedAt)
            put("synced", if (record.synced) 1 else 0)
            put("server_message", record.serverMessage)
            put("note", record.note)
        }
        return helper.writableDatabase.insertWithOnConflict(
            "scans",
            null,
            values,
            android.database.sqlite.SQLiteDatabase.CONFLICT_IGNORE,
        ) != -1L
    }

    /**
     * Returns the most recent scans, ordered by time descending.
     *
     * @param limit maximum number of rows
     * @param offset number of rows to skip (for pagination)
     */
    fun recent(limit: Int, offset: Int = 0): List<ScanRecord> {
        helper.readableDatabase.query(
            "scans",
            null,
            null,
            null,
            null,
            null,
            "scanned_at DESC, id DESC",
            "${limit.toString()} OFFSET ${offset.toString()}",
        ).use { cursor ->
            val out = ArrayList<ScanRecord>(cursor.count)
            while (cursor.moveToNext()) out.add(cursor.toRecord())
            return out
        }
    }

    /** Total number of scans in the database (used for pagination UI). */
    fun totalCount(): Int =
        helper.readableDatabase.rawQuery(
            "SELECT COUNT(*) FROM scans",
            null,
        ).use { cursor -> if (cursor.moveToFirst()) cursor.getInt(0) else 0 }

    fun pending(limit: Int): List<ScanRecord> {
        helper.readableDatabase.query(
            "scans",
            null,
            "synced = 0 AND source = ?",
            arrayOf(ScanSource.OFFLINE.name),
            null,
            null,
            "scanned_at ASC, id ASC",
            limit.toString(),
        ).use { cursor ->
            val out = ArrayList<ScanRecord>(cursor.count)
            while (cursor.moveToNext()) out.add(cursor.toRecord())
            return out
        }
    }

    fun pendingCount(): Int =
        helper.readableDatabase.rawQuery(
            "SELECT COUNT(*) FROM scans WHERE synced = 0 AND source = ?",
            arrayOf(ScanSource.OFFLINE.name),
        ).use { cursor -> if (cursor.moveToFirst()) cursor.getInt(0) else 0 }

    fun hasAdmitted(ticketId: String, since: Long): Boolean =
        helper.readableDatabase.rawQuery(
            "SELECT 1 FROM scans WHERE ticket_id = ? AND result = ? AND scanned_at >= ? LIMIT 1",
            arrayOf(ticketId, CheckinResult.VALID.wire, since.toString()),
        ).use { cursor -> cursor.moveToFirst() }

    fun statsSince(since: Long, now: Long): SessionStats {
        val db = helper.readableDatabase
        var total = 0
        var admitted = 0
        var lastScanAt: Long? = null

        db.rawQuery(
            "SELECT COUNT(*), " +
                "SUM(CASE WHEN result = ? THEN 1 ELSE 0 END), " +
                "MAX(scanned_at) " +
                "FROM scans WHERE scanned_at >= ?",
            arrayOf(CheckinResult.VALID.wire, since.toString()),
        ).use { cursor ->
            if (cursor.moveToFirst()) {
                total = cursor.getInt(0)
                admitted = if (cursor.isNull(1)) 0 else cursor.getInt(1)
                lastScanAt = if (cursor.isNull(2)) null else cursor.getLong(2)
            }
        }

        val byResult = LinkedHashMap<CheckinResult, Int>()
        db.rawQuery(
            "SELECT result, COUNT(*) FROM scans WHERE scanned_at >= ? GROUP BY result",
            arrayOf(since.toString()),
        ).use { cursor ->
            while (cursor.moveToNext()) {
                CheckinResult.fromWire(cursor.getString(0))?.let { byResult[it] = cursor.getInt(1) }
            }
        }

        val fiveMinutesAgo = maxOf(since, now - FIVE_MINUTES_MS)
        val recent = db.rawQuery(
            "SELECT COUNT(*) FROM scans WHERE scanned_at >= ?",
            arrayOf(fiveMinutesAgo.toString()),
        ).use { cursor -> if (cursor.moveToFirst()) cursor.getInt(0) else 0 }

        return SessionStats(
            total = total,
            admitted = admitted,
            denied = total - admitted,
            pending = pendingCount(),
            lastScanAt = lastScanAt,
            lastFiveMinutes = recent,
            byResult = byResult,
        )
    }

    /**
     * Deletes already-synced scans older than the given timestamp.
     *
     * Used to keep the local database from growing forever on long-term
     * deployments. Unsynced scans are never deleted — they must survive until
     * the server confirms them.
     *
     * @return number of rows deleted
     */
    fun deleteOldSyncedScans(olderThanMillis: Long): Int {
        return helper.writableDatabase.delete(
            "scans",
            "synced = 1 AND scanned_at < ?",
            arrayOf(olderThanMillis.toString()),
        )
    }

    /**
     * Applies the server's verdict to the local rows. The server is the final
     * authority: if it mapped a local `valid` to `conflict`, history must show
     * that, not the optimistic local answer.
     */
    fun applySyncOutcome(items: List<SyncItemResult>) {
        if (items.isEmpty()) return
        val db = helper.writableDatabase
        db.beginTransaction()
        try {
            for (item in items) {
                val values = ContentValues().apply {
                    put("synced", 1)
                    item.result?.let { put("result", it.wire) }
                    item.message?.let { put("server_message", it) }
                }
                db.update("scans", values, "client_scan_id = ?", arrayOf(item.clientScanId))
            }
            db.setTransactionSuccessful()
        } finally {
            db.endTransaction()
        }
    }

    fun deleteAll() {
        helper.writableDatabase.delete("scans", null, null)
    }

    /**
     * Возвращает все сканы за период (или все, если sinceMillis = 0).
     * Используется для экспорта в CSV.
     */
    fun scansSince(sinceMillis: Long): List<ScanRecord> {
        val whereClause = if (sinceMillis > 0L) "scanned_at >= ?" else null
        val whereArgs = if (sinceMillis > 0L) arrayOf(sinceMillis.toString()) else null
        helper.readableDatabase.query(
            "scans",
            null,
            whereClause,
            whereArgs,
            null,
            null,
            "scanned_at DESC, id DESC",
            null,
        ).use { cursor ->
            val out = ArrayList<ScanRecord>(cursor.count)
            while (cursor.moveToNext()) out.add(cursor.toRecord())
            return out
        }
    }

    /**
     * Ищет сканы по номеру билета или ID билета.
     * Регистронезависимый部分匹配.
     */
    fun searchByTicket(query: String): List<ScanRecord> {
        val like = "%$query%"
        helper.readableDatabase.query(
            "scans",
            null,
            "ticket_number LIKE ? OR ticket_id LIKE ?",
            arrayOf(like, like),
            null,
            null,
            "scanned_at DESC, id DESC",
            null,
        ).use { cursor ->
            val out = ArrayList<ScanRecord>(cursor.count)
            while (cursor.moveToNext()) out.add(cursor.toRecord())
            return out
        }
    }

    private fun Cursor.toRecord(): ScanRecord {
        val result = CheckinResult.fromWire(getString(getColumnIndexOrThrow("result")))
            ?: CheckinResult.UNKNOWN_TICKET
        val source = runCatching {
            ScanSource.valueOf(getString(getColumnIndexOrThrow("source")))
        }.getOrDefault(ScanSource.LOCAL)

        fun nullableString(column: String): String? {
            val index = getColumnIndexOrThrow(column)
            return if (isNull(index)) null else getString(index)
        }

        return ScanRecord(
            clientScanId = getString(getColumnIndexOrThrow("client_scan_id")),
            ticketId = getString(getColumnIndexOrThrow("ticket_id")),
            ticketNumber = nullableString("ticket_number"),
            result = result,
            source = source,
            sessionId = nullableString("session_id"),
            deviceId = nullableString("device_id"),
            scannedAt = getLong(getColumnIndexOrThrow("scanned_at")),
            synced = getInt(getColumnIndexOrThrow("synced")) != 0,
            serverMessage = nullableString("server_message"),
            note = nullableString("note"),
        )
    }

    private companion object {
        const val FIVE_MINUTES_MS = 5 * 60 * 1000L
    }
}
