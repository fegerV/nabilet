package com.nabilet.checker.sync

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

/**
 * Offline scans are synced by WorkManager, not by an in-process coroutine:
 * the app is often killed between scans, and the queue has to survive that.
 *
 * The periodic job is the safety net (15 minutes is WorkManager's minimum).
 * `enqueue()` is called immediately after every queued scan and by the UI.
 */
class SyncScheduler(private val context: Context) {

    private val workManager: WorkManager = WorkManager.getInstance(context.applicationContext)

    fun enqueue() {
        val request = OneTimeWorkRequestBuilder<SyncWorker>()
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build(),
            )
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()

        workManager.enqueueUniqueWork(UNIQUE_ONE_TIME, ExistingWorkPolicy.KEEP, request)
    }

    fun schedulePeriodic() {
        val request = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build(),
            )
            .build()

        workManager.enqueueUniquePeriodicWork(
            UNIQUE_PERIODIC,
            ExistingPeriodicWorkPolicy.KEEP,
            request,
        )
    }

    fun cancelAll() {
        workManager.cancelUniqueWork(UNIQUE_ONE_TIME)
        workManager.cancelUniquePeriodicWork(UNIQUE_PERIODIC)
    }

    private companion object {
        const val UNIQUE_ONE_TIME = "nabilet-checker-sync"
        const val UNIQUE_PERIODIC = "nabilet-checker-sync-periodic"
    }
}
