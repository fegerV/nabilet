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
 */
class CheckerDatabase(context: Context) :
    SQLiteOpenHelper(context.applicationContext, NAME, null, VERSION) {

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
        // Version 1 is the first shipped schema. When it changes, add explicit
        // migrations here; silently dropping a shift's unsynced scans would be
        // data loss at the door.
    }

    companion object {
        private const val NAME = "nabilet_checker.db"
        private const val VERSION = 1
    }
}
