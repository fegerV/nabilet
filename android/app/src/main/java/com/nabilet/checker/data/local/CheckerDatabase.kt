package com.nabilet.checker.data.local

import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

/**
 * Hand-written SQLite. No Room/KSP: the schema is four small tables and the
 * build has to stay reproducible on a machine that only has the Android SDK.
 *
 * `client_scan_id` is UNIQUE: the same scan cannot be recorded twice even if
 * the camera reports one QR several times while the first request is in flight.
 *
 * WAL mode is enabled so that UI reads (history, stats) and background writes
 * (sync worker applying server verdicts) do not block each other.
 */
class CheckerDatabase(context: Context) :
    SQLiteOpenHelper(context.applicationContext, NAME, null, VERSION) {

    init {
        // Enable WAL so readers and writers don't block each other on the same
        // database file. Safe to call here: onCreate/onUpgrade run before any
        // user queries.
        setWriteAheadLoggingEnabled(true)
    }

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """
            CREATE TABLE scans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_scan_id TEXT NOT NULL UNIQUE,
                ticket_id TEXT NOT NULL,
                ticket_number TEXT,
                result TEXT NOT NULL,
                source TEXT NOT NULL,
                session_id TEXT,
                device_id TEXT,
                scanned_at INTEGER NOT NULL,
                synced INTEGER NOT NULL DEFAULT 0,
                server_message TEXT,
                note TEXT
            )
            """.trimIndent(),
        )
        db.execSQL("CREATE INDEX idx_scans_scanned_at ON scans(scanned_at)")
        db.execSQL("CREATE INDEX idx_scans_pending ON scans(synced, scanned_at)")
        db.execSQL("CREATE INDEX idx_scans_ticket ON scans(ticket_id, scanned_at)")
        db.execSQL("CREATE INDEX idx_scans_synced_ticket ON scans(synced, ticket_id)")

        db.execSQL(
            """
            CREATE TABLE bundles (
                id TEXT PRIMARY KEY,
                bundle_hash TEXT NOT NULL,
                schema_version INTEGER NOT NULL,
                key_fingerprint TEXT NOT NULL,
                device_public_id TEXT NOT NULL,
                org_internal_id INTEGER NOT NULL,
                event_internal_id INTEGER NOT NULL,
                session_internal_id INTEGER,
                event_public_id TEXT NOT NULL,
                session_public_id TEXT,
                ticket_count INTEGER NOT NULL,
                revoked_count INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                generated_at INTEGER,
                downloaded_at INTEGER NOT NULL,
                canonical_json TEXT NOT NULL,
                status TEXT NOT NULL
            )
            """.trimIndent(),
        )

        db.execSQL(
            """
            CREATE TABLE bundle_tickets (
                bundle_id TEXT NOT NULL,
                ticket_id TEXT NOT NULL,
                ticket_number TEXT NOT NULL,
                status TEXT NOT NULL,
                section TEXT NOT NULL,
                PRIMARY KEY (bundle_id, ticket_id)
            )
            """.trimIndent(),
        )
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        // Version 1 -> 2: add index for fast pending-scan lookups and enable WAL.
        if (oldVersion < 2) {
            db.execSQL("CREATE INDEX IF NOT EXISTS idx_scans_synced_ticket ON scans(synced, ticket_id)")
        }
    }

    companion object {
        private const val NAME = "nabilet_checker.db"
        private const val VERSION = 2
    }
}
