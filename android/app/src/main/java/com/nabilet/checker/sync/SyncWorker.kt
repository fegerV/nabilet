package com.nabilet.checker.sync

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.nabilet.checker.CheckerApp
import com.nabilet.checker.data.repository.SyncOutcomeKind

/**
 * Syncs the offline queue. Retry is reserved for conditions that can heal by
 * themselves (network, 5xx). A rejected token or a device mismatch is a
 * failure: retrying it forever would drain the battery and hide the problem
 * from the operator.
 */
class SyncWorker(
    appContext: Context,
    params: WorkerParameters,
) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val app = applicationContext as? CheckerApp ?: return Result.failure()
        val container = app.container
        val outcome = container.repository.syncNow()

        return when (outcome.kind) {
            SyncOutcomeKind.DONE -> {
                if (container.repository.pendingCount() > 0) {
                    // More scans arrived while the worker was running (or the
                    // batch limit was hit). Chain another run immediately.
                    container.syncScheduler.enqueue()
                }
                Result.success()
            }

            SyncOutcomeKind.RETRY -> Result.retry()
            SyncOutcomeKind.AUTH, SyncOutcomeKind.FAILED -> Result.failure()
        }
    }
}
